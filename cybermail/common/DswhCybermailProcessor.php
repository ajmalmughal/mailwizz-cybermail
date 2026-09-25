<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DswhCybermailProcessor
 *
 * Handles CyberMail webhook deliveries and maps them onto MailWizz bounce and
 * complaint records.
 *
 * PAYLOAD (captured from live traffic)
 *   {
 *     "event": "MessageBounced",
 *     "timestamp": "2026-09-23T15:50:17.254634+00:00",
 *     "data": {
 *       "message_id": "42c348...@cpmail.cyberpersons.com",
 *       "from": "...", "to": "...", "subject": "...",
 *       "status": "bounced", "tags": [], "metadata": {...},
 *       "bounce_type": "hard",
 *       "bounce_message": "550 No such person at this address."
 *     }
 *   }
 *
 *   The event names differ from the documentation: the real ones are
 *   MessageDelivered and MessageBounced, not "delivered" and "bounced". The
 *   same name is sent in the X-Webhook-Event header. data.message_id equals the
 *   message_id returned by /email/v1/send and the Message-ID header of the
 *   delivered mail. The bounce reason field is bounce_message, not the
 *   documented error_message.
 *
 *   data.metadata carries an original_content block holding the full HTML and
 *   text body of the message. It is stripped before anything is logged.
 *
 * SIGNATURE
 *   X-Webhook-Signature: <lowercase hex HMAC-SHA256 of the raw request body>
 *
 *   The key is the signing secret used as a plain string (it is NOT hex
 *   decoded), and the body is hashed exactly as received, so it must never be
 *   decoded and re-encoded first. There is no timestamp in the header and no
 *   event id, so replay protection is by body hash (see alreadySeen()).
 *
 * TEST EVENT
 *   The dashboard's Test button sends {"event":"test",...} with NO signature
 *   header. It is answered 200 and ignored before signature checking, because
 *   the webhook is disabled after repeated failures and a failed test would
 *   count towards that. It cannot change any data, so this is safe.
 *
 * DELIVERY BEHAVIOUR (CyberMail side)
 *   Events arrive minutes after the message, observed 5 to 10 minutes, so do
 *   not expect real time. CONFIRMED by support (ticket HZVIQOVQN): delivery
 *   is a SINGLE ATTEMPT with a 10 second timeout, no automatic retry at all.
 *   An endpoint is auto-disabled after 10 consecutive failures, reset by any
 *   single success. So this class answers 200 for anything it has accepted,
 *   including events it deliberately ignores, and 401 only when a request
 *   cannot be authenticated: a slow or 5xx response here can silently stop
 *   every future bounce from being recorded.
 *
 * CONFIRMED EVENT NAMES (support, ticket HZVIQOVQN, 2026-09):
 *   MessageSent, MessageDelivered, MessageDeliveryFailed, MessageBounced.
 *   MessageLoaded and MessageLinkClicked are defined but confirmed to never
 *   currently fire (CyberMail does no open/click tracking of its own yet),
 *   so they are not specially handled; MailWizz's own tracking is unaffected.
 *
 * CONFIRMED: NO COMPLAINT WEBHOOK EXISTS
 *   Support confirmed there is no dedicated complaint/FBL event, and none is
 *   planned yet. A complaint instead suppresses the recipient on CyberMail's
 *   side; the NEXT send attempt to that address is rejected with HTTP 500
 *   send_failed, message containing "suppressed" (handled in the delivery
 *   server model, not here). So this processor cannot and does not detect a
 *   complaint as it happens, only ever after the fact, on a later send.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 */
class DswhCybermailProcessor
{
    /**
     * Classes an incoming event name is sorted into, see classify().
     */
    public const CLASS_TEST       = 'test';
    public const CLASS_BOUNCED    = 'bounced';
    public const CLASS_FAILED     = 'failed';
    public const CLASS_IGNORED    = 'ignored';

    /**
     * How long a processed body hash is remembered, seconds.
     */
    public const DEDUPE_TTL = 172800;

    /**
     * Maximum length of a bounce message stored against a subscriber.
     */
    public const MAX_BOUNCE_MESSAGE = 250;

    /**
     * Maximum length of a logged event body.
     */
    public const MAX_LOG_BODY = 1500;

    /**
     * Entry point, registered through the dswh_process_map filter.
     *
     * @param DeliveryServer $server
     * @param Controller $controller
     *
     * @return void
     */
    public function process($server, $controller = null): void
    {
        try {
            $this->handleRequest($server);
        } catch (Throwable $e) {
            // A 500 here is expensive: CyberMail disables a webhook that keeps
            // failing. So catch everything, record it, and still answer 200.
            error_log(sprintf(
                'CYBERMAIL-WEBHOOK fatal in processor: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            try {
                $this->log($server, sprintf('fatal: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
            } catch (Throwable $ignored) {
                // Nothing further we can do.
            }

            $this->respond(200);
        }
    }

    /**
     * The actual request handling, wrapped by process() above.
     *
     * @param DeliveryServer $server
     *
     * @return void
     */
    protected function handleRequest($server): void
    {
        $rawBody = (string)file_get_contents('php://input');

        $payload = json_decode($rawBody, true);
        $event   = '';

        if (is_array($payload) && isset($payload['event']) && is_scalar($payload['event'])) {
            $event = (string)$payload['event'];
        }

        if ($event === '') {
            $event = (string)($_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '');
        }

        $class = $this->classify($event);

        // The dashboard test event is unsigned, see the class notes.
        if ($class === self::CLASS_TEST) {
            $this->log($server, 'test event received, endpoint is reachable');
            $this->respond(200);
            return;
        }

        // Authenticate before acting. An unsigned request must never be able
        // to blacklist a subscriber.
        if (!$this->verifySignature($rawBody, (string)$server->password, $server)) {
            $this->respond(401);
            return;
        }

        if (!is_array($payload)) {
            $this->log($server, 'payload was not valid json, ignoring');
            $this->respond(200);
            return;
        }

        $data = !empty($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        // Deduplicate on the body hash. CyberMail sends no event id, and a
        // retried delivery carries an identical body.
        $dedupeId = sha1($rawBody);

        if ($this->alreadySeen($dedupeId)) {
            $this->respond(200);
            return;
        }

        // Everything is logged, including ignored events, because the
        // complaint and failure shapes have not been seen yet.
        $this->log($server, sprintf('received %s: %s', $event, $this->compactForLog($data)));

        if ($class === self::CLASS_IGNORED) {
            $this->markSeen($dedupeId);
            $this->respond(200);
            return;
        }

        try {
            $this->handleEvent($server, $class, $event, $data);
        } catch (Exception $e) {
            // Answer 200 anyway. A retry would hit the same error.
            $this->log($server, sprintf('error handling %s: %s', $event, $e->getMessage()));
        }

        $this->markSeen($dedupeId);
        $this->respond(200);
    }

    /**
     * Sort an event name into a class.
     *
     * Matched against the exact names confirmed by support (ticket
     * HZVIQOVQN): MessageSent, MessageDelivered, MessageDeliveryFailed,
     * MessageBounced, MessageLoaded, MessageLinkClicked. Compared lowercase
     * with punctuation stripped, so "MessageBounced", "message.bounced" and
     * "message_bounced" all still match, in case a future revision changes
     * casing or separators without notice.
     *
     * There is no complaint class: support confirmed no complaint webhook
     * exists, see the class docblock.
     *
     * Ignored on purpose:
     *   MessageSent, MessageDelivered - informational
     *   MessageLoaded, MessageLinkClicked - confirmed to never fire today,
     *     and MailWizz does its own open/click tracking regardless
     *
     * @param string $event
     *
     * @return string
     */
    protected function classify(string $event): string
    {
        $name = strtolower((string)preg_replace('/[^a-zA-Z]/', '', $event));

        if ($name === 'test') {
            return self::CLASS_TEST;
        }

        if ($name === 'messagebounced') {
            return self::CLASS_BOUNCED;
        }

        if ($name === 'messagedeliveryfailed') {
            return self::CLASS_FAILED;
        }

        return self::CLASS_IGNORED;
    }

    /**
     * Route a single event.
     *
     * @param DeliveryServer $server
     * @param string $class
     * @param string $event
     * @param array $data
     *
     * @return void
     */
    protected function handleEvent($server, string $class, string $event, array $data): void
    {
        $messageId = '';
        foreach (['message_id', 'messageId', 'id'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $messageId = (string)$data[$key];
                break;
            }
        }

        if ($messageId === '') {
            $this->log($server, sprintf('%s carried no message id, cannot attribute it', $event));
            return;
        }

        [$campaign, $subscriber] = $this->resolveSubscriber($server, $messageId);

        if (empty($campaign) || empty($subscriber)) {
            // Normal for transactional mail, which has no campaign behind it,
            // for a campaign sent by a different delivery server, and for rows
            // whose delivery log has been pruned.
            $this->log($server, sprintf('%s for message id %s could not be attributed to a campaign subscriber on this server, ignoring', $event, $messageId));
            return;
        }

        $this->log($server, sprintf('%s for message id %s attributed to subscriber #%d on campaign #%d', $event, $messageId, (int)$subscriber->subscriber_id, (int)$campaign->campaign_id));

        if ($class === self::CLASS_BOUNCED) {
            $declared = isset($data['bounce_type']) ? strtolower((string)$data['bounce_type']) : '';

            $this->handleBounce($campaign, $subscriber, $declared, $data);
            return;
        }

        if ($class === self::CLASS_FAILED) {
            // CyberMail itself failed to deliver. That says nothing about
            // whether the address is valid, so it is recorded as an internal
            // bounce and does not blacklist the subscriber.
            $this->recordBounce($campaign, $subscriber, CampaignBounceLog::BOUNCE_INTERNAL, $this->buildBounceMessage($data));
            return;
        }

        $this->log($server, sprintf('unhandled event %s', $event));
    }

    /**
     * Find the campaign and subscriber behind a CyberMail message id.
     *
     * Scoped to the delivery server that actually sent the message. MailWizz
     * rotates across several delivery servers within a single campaign, so a
     * message id alone is not enough to identify a row safely, and where an
     * operator runs several CyberMail servers on one API key every event is
     * delivered to every webhook registered on the account. With scoping, only
     * the server that sent the message acts on the event.
     *
     * server_id is nullable in mw_campaign_delivery_log, so a NULL row is still
     * accepted rather than silently dropped.
     *
     * @param DeliveryServer $server
     * @param string $messageId
     *
     * @return array [Campaign|null, ListSubscriber|null]
     */
    protected function resolveSubscriber($server, string $messageId): array
    {
        $criteria = new CDbCriteria();
        $criteria->addCondition(
            '`email_message_id` = :email_message_id AND `status` = :status ' .
            'AND (`server_id` = :server_id OR `server_id` IS NULL)'
        );
        $criteria->params = [
            'email_message_id' => $messageId,
            'status'           => CampaignDeliveryLog::STATUS_SUCCESS,
            'server_id'        => (int)$server->server_id,
        ];

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return [null, null];
        }

        /** @var Campaign|null $campaign */
        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return [null, null];
        }

        /** @var ListSubscriber|null $subscriber */
        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => (int)$campaign->list_id,
            'subscriber_id' => (int)$deliveryLog->subscriber_id,
            'status'        => ListSubscriber::STATUS_CONFIRMED,
        ]);

        if (empty($subscriber)) {
            return [null, null];
        }

        return [$campaign, $subscriber];
    }

    /**
     * A bounce.
     *
     * CyberMail states bounce_type explicitly as "hard" or "soft", so there is
     * nothing to infer from reply codes.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $declared
     * @param array $data
     *
     * @return void
     */
    protected function handleBounce($campaign, $subscriber, string $declared, array $data): void
    {
        if ($declared === 'hard') {
            $bounceType = CampaignBounceLog::BOUNCE_HARD;
        } else {
            // "soft", or an unknown value: treat as soft. Wrongly soft-bouncing
            // a valid address costs one retry; wrongly hard-bouncing it removes
            // a real subscriber permanently.
            $bounceType = CampaignBounceLog::BOUNCE_SOFT;
        }

        $this->recordBounce($campaign, $subscriber, $bounceType, $this->buildBounceMessage($data));
    }

    /**
     * Compose a readable bounce reason from whichever field is present.
     * bounce_message is the one seen on the wire, the others are fallbacks for
     * the event shapes not yet observed.
     *
     * @param array $data
     *
     * @return string
     */
    protected function buildBounceMessage(array $data): string
    {
        $message = '';

        foreach (['bounce_message', 'error_message', 'failure_reason', 'error', 'reason', 'message'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $message = (string)$data[$key];
                break;
            }
        }

        // SMTP transcripts can arrive multi-line. Flatten and cap so the value
        // fits the column and stays readable in the UI.
        $message = trim((string)preg_replace('/\s+/', ' ', $message));

        if ($message === '') {
            $message = 'BOUNCED BACK';
        }

        if (strlen($message) > self::MAX_BOUNCE_MESSAGE) {
            $message = substr($message, 0, self::MAX_BOUNCE_MESSAGE);
        }

        return $message;
    }

    /**
     * Write a bounce log entry, blacklisting on a hard bounce.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $bounceType
     * @param string $message
     *
     * @return void
     */
    protected function recordBounce($campaign, $subscriber, string $bounceType, string $message): void
    {
        // Never record the same subscriber twice for the same campaign.
        $count = CampaignBounceLog::model()->countByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);

        if (!empty($count)) {
            return;
        }

        $bounceLog = new CampaignBounceLog();
        $bounceLog->campaign_id   = (int)$campaign->campaign_id;
        $bounceLog->subscriber_id = (int)$subscriber->subscriber_id;
        $bounceLog->message       = $message;
        $bounceLog->bounce_type   = $bounceType;
        $bounceLog->save();

        if ($bounceLog->bounce_type === CampaignBounceLog::BOUNCE_HARD) {
            $subscriber->addToBlacklist((string)$bounceLog->message);
        }
    }

    /**
     * Verify X-Webhook-Signature.
     *
     * The header is the lowercase hex HMAC-SHA256 of the raw body, keyed with
     * the signing secret as a plain string. A "sha256=" prefix is tolerated in
     * case CyberMail adds one later, although none is sent today.
     *
     * @param string $rawBody
     * @param string $secret
     * @param DeliveryServer $server
     *
     * @return bool
     */
    protected function verifySignature(string $rawBody, string $secret, $server): bool
    {
        if ($secret === '') {
            $this->log($server, 'no webhook signing secret is configured on this delivery server, rejecting. Paste the secret shown when you created the webhook into the "Webhook signing secret" field.');
            return false;
        }

        $header = trim((string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
        if ($header === '') {
            $this->log($server, 'request carried no X-Webhook-Signature header, rejecting');
            return false;
        }

        if (stripos($header, 'sha256=') === 0) {
            $header = substr($header, 7);
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (hash_equals($expected, strtolower($header))) {
            return true;
        }

        $this->log($server, 'signature did not match, rejecting. Check the secret matches the webhook in your CyberMail dashboard.');

        return false;
    }

    /**
     * @param string $dedupeId
     *
     * @return bool
     */
    protected function alreadySeen(string $dedupeId): bool
    {
        if ($dedupeId === '') {
            return false;
        }

        return (bool)cache()->get($this->dedupeKey($dedupeId));
    }

    /**
     * @param string $dedupeId
     *
     * @return void
     */
    protected function markSeen(string $dedupeId): void
    {
        if ($dedupeId === '') {
            return;
        }

        cache()->set($this->dedupeKey($dedupeId), 1, self::DEDUPE_TTL);
    }

    /**
     * @param string $dedupeId
     *
     * @return string
     */
    protected function dedupeKey(string $dedupeId): string
    {
        return 'cybermail.webhook.' . $dedupeId;
    }

    /**
     * Shrink an event's data for the log: the original_content block holds the
     * whole message body and would make every line enormous.
     *
     * @param array $data
     *
     * @return string
     */
    protected function compactForLog(array $data): string
    {
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            unset($data['metadata']['original_content']);
        }

        $json = (string)json_encode($data);

        if (strlen($json) > self::MAX_LOG_BODY) {
            $json = substr($json, 0, self::MAX_LOG_BODY) . '...';
        }

        return $json;
    }

    /**
     * Append to a dedicated log file. Yii::log at info level is normally
     * filtered out by MailWizz's production log routes, so this writes
     * directly and does not depend on log configuration.
     *
     * The runtime directory is resolved defensively, and logging must never be
     * able to break the response.
     *
     * @param DeliveryServer $server
     * @param string $message
     *
     * @return void
     */
    protected function log($server, string $message): void
    {
        $line = sprintf(
            "[%s] CYBERMAIL-WEBHOOK server=#%d %s\n",
            date('Y-m-d H:i:s'),
            (int)$server->server_id,
            $message
        );

        try {
            $dir = '';

            // Preferred: the alias MailWizz always registers.
            $alias = Yii::getPathOfAlias('common.runtime');
            if (!empty($alias) && is_dir((string)$alias)) {
                $dir = (string)$alias;
            }

            if ($dir === '') {
                $dir = (string)app()->getRuntimePath();
            }

            if ($dir !== '' && is_dir($dir)) {
                if (@file_put_contents($dir . '/cybermail-webhook.log', $line, FILE_APPEND | LOCK_EX) !== false) {
                    return;
                }
            }
        } catch (Throwable $e) {
            // Fall through to error_log below.
        }

        error_log($line);
    }

    /**
     * Emit the response status and stop.
     *
     * Deliberately does NOT call request()->sendHeaders(), which does not exist
     * on MailWizz's FrontendHttpRequest and would surface as an HTTP 500.
     * http_response_code() is plain PHP and needs no framework support.
     *
     * @param int $code
     *
     * @return void
     */
    protected function respond(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }

        app()->end();
    }
}
