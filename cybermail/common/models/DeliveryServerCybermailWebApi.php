<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DeliveryServerCybermailWebApi
 *
 * Delivery server implementation for the CyberMail REST API (v1), the email
 * delivery service run by CyberPanel.
 *
 * Everything below was checked against the live API, not just the published
 * documentation, which differs from the real behaviour in several places:
 *
 *  - Base url is https://platform.cyberpersons.com/email/v1
 *  - Auth is "Authorization: Bearer <key>". The key needs can_send.
 *  - One recipient per request ("to" is a single address). That suits
 *    MailWizz, which already sends per subscriber.
 *  - "from" MUST be a bare address. "Name <address>" is refused with 400
 *    invalid_request. The display name goes in a separate "from_name" field.
 *    That field is not in the public documentation but is honoured.
 *  - "reply_to", "html", "text" and "headers" all work. A custom header such as
 *    List-Unsubscribe arrives in the delivered message unchanged.
 *  - "tags" are turned into a visible X-Tags header on the delivered message,
 *    so this class never sends them.
 *  - "metadata" is echoed back in webhooks, but so is the full message body,
 *    which makes every webhook heavy. Nothing is stored there: messages are
 *    correlated by message_id instead.
 *  - The domain of "from" must be registered AND verified in the CyberMail
 *    account. An unknown domain returns 403 domain_not_found.
 *  - Send success is HTTP 202 {"success":true,"data":{"message_id":"...",
 *    "status":"sent",...}}. The message_id is stored by MailWizz as
 *    email_message_id, which is what the webhook handler correlates against.
 *  - Errors are {"success":false,"error":{"type":"...","message":"..."}}.
 *
 * CONFIRMED BY CYBERMAIL SUPPORT (ticket HZVIQOVQN, 2026-09):
 *  - Attachments are genuinely unsupported by the REST API; no field exists,
 *    even internally. Their only path for attachments is a separate SMTP
 *    credential, which this class does not use.
 *  - from_name is the officially supported mechanism (255 char max).
 *  - There are TWO rate limit layers. The API layer enforces a SOFT,
 *    best-effort per-minute cap and returns 429 rate_limit_exceeded when it
 *    trips. Underneath that, the mail platform enforces a HARD per-second,
 *    per-hour and per-day cap per account; tripping THAT layer returns
 *    HTTP 500 send_failed with rate-limit wording in the message, not 429.
 *    Both are handled in handleApiError() below.
 *  - There is no dedicated complaint/FBL webhook event. A complaint instead
 *    suppresses the recipient on CyberMail's side; a later send to that
 *    address is rejected with 500 send_failed, message containing
 *    "suppressed". The webhook processor no longer waits for a complaint
 *    event that will never arrive.
 *  - They run no bounce mailbox. Return-Path is our own "from" address, and
 *    MessageBounced webhooks (or polling GET /email/v1/messages/{id}, not
 *    used here) are the intended bounce signal. A DSN to our own domain is
 *    a secondary, optional path, not something this class relies on.
 *  - Full documented error list: 400 invalid_request, 401
 *    authentication_error, 403 forbidden (key/IP/domain restriction), 403
 *    domain_not_found, 403 domain_not_verified, 403 account_inactive, 429
 *    rate_limit_exceeded, 503 service_unavailable (retry), 500 send_failed
 *    (rate limit OR suppressed recipient, distinguished only by message
 *    text), 500 internal_error, 405 (wrong method, plain text response).
 *    By request, domain_not_found and domain_not_verified do NOT deactivate
 *    the server (see DOMAIN_ERROR_TYPES below) — only authentication_error,
 *    forbidden and account_inactive do.
 *
 * @copyright 2026 M. Ajmal Mughal
 * @license   MIT
 */
class DeliveryServerCybermailWebApi extends DeliveryServer
{
    /**
     * Endpoint used for sending.
     */
    public const SEND_URL = 'https://platform.cyberpersons.com/email/v1/send';

    /**
     * Headers this class never forwards, lowercased.
     *
     * Identity headers are built by CyberMail from the "from", "to", "subject"
     * and "reply_to" fields, and the transport headers describe a MIME
     * structure that CyberMail builds itself. Sending them risks a 400 for the
     * whole request, which would fail every subscriber in a campaign.
     *
     * Dropped SILENTLY: MailWizz supplies some of these on every message, so a
     * warning per subscriber would fill the delivery log with noise.
     */
    public const SKIPPED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'reply-to',
        'sender',
        'return-path',
        'message-id',
        'date',
        'dkim-signature',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
        'received',
    ];

    /**
     * Error types that mean nothing will send until a human fixes the
     * account. The server is disabled (STATUS_DISABLED) on these, not set to
     * MailWizz's own STATUS_INACTIVE — see deactivate() below for why.
     *
     * "forbidden" confirmed by support: covers the key's IP restriction, a
     * missing can_send permission, or a from domain outside the key's
     * allowed list. All three need a human to fix, same as the others here.
     *
     * domain_not_found and domain_not_verified are deliberately NOT in this
     * list: by request, a wrong or unverified "from" domain (for example a
     * campaign using a domain that was never added to CyberMail) no longer
     * takes the server offline. It is instead handled as a message level
     * failure, see handleApiError() below, so the campaign stops trying that
     * domain's sends but the server stays active for correctly configured
     * campaigns. Every affected recipient still shows the reason clearly in
     * the delivery log; nothing here silences it.
     */
    public const FATAL_ERROR_TYPES = [
        'authentication_error',
        'forbidden',
        'account_inactive',
    ];

    /**
     * Error types that mean this specific campaign's "from" domain is wrong,
     * not that the whole account is broken. Every recipient in the campaign
     * will still fail the same way, but the server itself is left active so
     * other, correctly configured campaigns keep sending.
     */
    public const DOMAIN_ERROR_TYPES = [
        'domain_not_found',
        'domain_not_verified',
    ];

    /**
     * Substrings looked for in a 500 send_failed message, confirmed by
     * support to distinguish the hard rate limit from a suppressed
     * recipient. Both share the same error type and HTTP code, so only the
     * message text tells them apart.
     */
    public const RATE_LIMIT_MESSAGE_HINTS = ['rate limit', 'rate_limit'];
    public const SUPPRESSED_MESSAGE_HINTS = ['suppress'];

    /**
     * @var string
     */
    protected $serverType = 'cybermail-web-api';

    /**
     * @var string
     */
    protected $_initStatus;

    /**
     * @var string
     */
    protected $_providerUrl = 'https://platform.cyberpersons.com/';

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['username', 'required'],
            ['username', 'length', 'max' => 255],
            ['password', 'length', 'max' => 255],
        ];

        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        $labels = [
            'username' => t('servers', 'Api key'),
            'password' => t('servers', 'Webhook signing secret'),
        ];

        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    /**
     * @return array
     */
    public function attributeHelpTexts()
    {
        $texts = [
            'username'   => t('servers', 'Your CyberMail API key, created in your CyberMail dashboard under API Keys. It needs the can_send permission and is sent as a bearer token.'),
            'password'   => t('servers', 'The signing secret for your webhook, shown once when you create the webhook in the CyberMail dashboard. Required: webhook requests that cannot be verified against this secret are rejected, so bounces and complaints will not be recorded without it.'),
            'from_email' => t('servers', 'The domain of this email address must be added AND verified as a sending domain in your CyberMail account, otherwise all sending will fail.'),
        ];

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    /**
     * @return array
     */
    public function attributePlaceholders()
    {
        $placeholders = [
            'username' => 'your-cybermail-api-key',
            'password' => 'your-webhook-secret',
        ];

        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * @param string $className
     * @return DeliveryServer
     */
    public static function model($className = self::class)
    {
        /** @var DeliveryServer $model */
        $model = parent::model($className);

        return $model;
    }

    /**
     * @param array $params
     * @return array
     * @throws CException
     */
    public function send(array $params = []): array
    {
        /** @var array $params */
        $params = (array)hooks()->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!ArrayHelper::hasKeys($params, ['from', 'to', 'subject', 'body'])) {
            return [];
        }

        [$toEmail]              = $this->getMailer()->findEmailAndName($params['to']);
        [$fromEmail, $fromName] = $this->getMailer()->findEmailAndName($params['from']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = null;
        if (!empty($params['replyTo'])) {
            [$replyToEmail] = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $sent = [];

        try {
            // "from" must be a bare address, see the class notes.
            $sendParams = [
                'from'    => $fromEmail,
                'to'      => $toEmail,
                'subject' => (string)$params['subject'],
            ];

            $fromName = $this->sanitizeFromName((string)$fromName);
            if ($fromName !== '') {
                $sendParams['from_name'] = $fromName;
            }

            if (!empty($replyToEmail)) {
                $sendParams['reply_to'] = $replyToEmail;
            }

            // Bodies. CyberMail accepts html and/or text.
            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;

            if ($onlyPlainText) {
                $sendParams['text'] = !empty($params['plainText'])
                    ? (string)$params['plainText']
                    : (string)CampaignHelper::htmlToText((string)$params['body']);
            } else {
                $sendParams['html'] = (string)$params['body'];

                // Same rule as MailWizz's own API servers (see
                // DeliveryServerMailjetWebApi): always send a text part,
                // generated from the HTML when MailWizz supplies none. The
                // server validation email, for one, arrives with no plainText.
                $sendParams['text'] = !empty($params['plainText'])
                    ? (string)$params['plainText']
                    : (string)CampaignHelper::htmlToText((string)$params['body']);
            }

            // Custom headers, minus identity and MIME headers.
            if (!empty($params['headers'])) {
                $headers = $this->filterHeaders($this->parseHeadersIntoKeyValue($params['headers']));

                if (!empty($headers)) {
                    $sendParams['headers'] = $headers;
                }
            }

            // Attachments. No attachment field is documented or known, so
            // nothing is sent. Logged rather than dropped silently, so a
            // missing attachment is never a mystery.
            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $this->getMailer()->addLog(sprintf(
                    'CyberMail: %d attachment(s) were NOT sent. Attachments are not supported by this integration because the CyberMail API documents no attachment field. This message was delivered without them.',
                    count(array_unique($params['attachments']))
                ));
            }

            $result = $this->apiRequest('POST', self::SEND_URL, $sendParams);
            $data   = (array)$result['data'];

            $messageId = '';
            if (!empty($data['data']['message_id'])) {
                $messageId = (string)$data['data']['message_id'];
            }

            if ($result['success'] && $messageId !== '') {
                $this->getMailer()->addLog('OK');
                $sent = ['message_id' => $messageId];
            } else {
                $this->handleApiError($data, (int)$result['httpCode'], (string)$result['error']);
            }
        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        hooks()->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return (array)$sent;
    }

    /**
     * Drop identity and MIME headers, and sanitise the rest.
     *
     * Everything else is forwarded: List-Unsubscribe, List-Unsubscribe-Post,
     * Feedback-ID and the X-Mw-* headers MailWizz adds all reach the
     * delivered message.
     *
     * @param array $headers
     *
     * @return array
     */
    protected function filterHeaders(array $headers): array
    {
        $out = [];

        foreach ($headers as $name => $value) {
            $name = trim((string)$name);

            if ($name === '' || $value === null || $value === '') {
                continue;
            }

            if (in_array(strtolower($name), self::SKIPPED_HEADERS, true)) {
                continue;
            }

            // Strip CR/LF to avoid header injection.
            $clean = trim((string)preg_replace('/[\r\n]+/', ' ', (string)$value));

            if ($clean === '') {
                continue;
            }

            $out[$name] = $clean;
        }

        return $out;
    }

    /**
     * Remove line breaks from the display name. CyberMail documents no other
     * restriction, so nothing else is changed.
     *
     * @param string $fromName
     *
     * @return string
     */
    protected function sanitizeFromName(string $fromName): string
    {
        return trim((string)preg_replace('/[\r\n]+/', ' ', $fromName));
    }

    /**
     * Set the server inactive and record why, in as many places as are
     * available, because a server that stops sending with no visible reason is
     * far worse than a noisy log.
     *
     *  1. A dedicated file, apps/common/runtime/cybermail-errors.log
     *  2. The Yii application log at ERROR level, which production log routes
     *     do keep (unlike INFO)
     *  3. The customer's message inbox in the MailWizz UI, when the server
     *     belongs to a customer
     *
     * @param string $reason
     * @return void
     */
    protected function deactivate(string $reason): void
    {
        // By request: use MailWizz's own disable(), STATUS_ACTIVE ->
        // STATUS_DISABLED, rather than setting STATUS_INACTIVE directly.
        // Both statuses are treated identically by the campaign send loop
        // (SendCampaignsCommand only picks up STATUS_ACTIVE servers, so
        // neither lets the next email through), but STATUS_DISABLED can be
        // flipped back with a single click via enable() in the UI, while
        // STATUS_INACTIVE is the status the "send validation email" flow
        // is normally used to clear.
        if ($this->getIsDisabled()) {
            return;
        }

        $this->disable();

        $line = sprintf(
            'CyberMail delivery server #%d (%s) was disabled: %s',
            (int)$this->server_id,
            (string)$this->name,
            $reason
        );

        Yii::log($line, CLogger::LEVEL_ERROR);

        // Dedicated file. Resolved defensively so a missing path or an
        // unwritable directory can never break a send.
        try {
            $dir = (string)Yii::getPathOfAlias('common.runtime');

            if ($dir === '' || !is_dir($dir)) {
                $dir = (string)app()->getRuntimePath();
            }

            if ($dir !== '' && is_dir($dir)) {
                @file_put_contents(
                    $dir . '/cybermail-errors.log',
                    sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line),
                    FILE_APPEND | LOCK_EX
                );
            }
        } catch (Throwable $e) {
            // Logging must never break sending.
        }

        $this->notifyCustomer($reason);
    }

    /**
     * Put the reason in the customer's message inbox, where it appears in the
     * MailWizz UI without needing shell access.
     *
     * Wrapped defensively: backend owned servers have no customer, and the
     * message model is not worth a fatal error if it is unavailable.
     *
     * @param string $reason
     * @return void
     */
    protected function notifyCustomer(string $reason): void
    {
        try {
            if (empty($this->customer_id) || !class_exists('CustomerMessage', false)) {
                return;
            }

            $message = new CustomerMessage();
            $message->customer_id = (int)$this->customer_id;
            $message->title       = 'Delivery server disabled: ' . (string)$this->name;
            $message->message     = sprintf(
                'Your CyberMail delivery server "%s" (#%d) has been disabled automatically. %s ' .
                'Sending will not resume until you review the cause and set the server back to active.',
                (string)$this->name,
                (int)$this->server_id,
                $reason
            );
            $message->save();
        } catch (Throwable $e) {
            Yii::log('CyberMail: could not create customer message: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
        }
    }

    /**
     * Decide what to do about a failed send, then throw so send() logs it.
     *
     * The envelope is {"success":false,"error":{"type":"...","message":"..."}}.
     * Only errors that make every later send fail identically deactivate the
     * server (see FATAL_ERROR_TYPES): wrongly deactivating a server stops a
     * whole campaign, so anything message level or transient just fails that
     * one subscriber and lets the campaign carry on.
     *
     * @param array $data
     * @param int $httpCode
     * @param string $transportError
     * @return void
     * @throws Exception
     */
    protected function handleApiError(array $data, int $httpCode, string $transportError = ''): void
    {
        // Transport level failure (timeout, DNS, connection reset). Transient,
        // keep the server enabled and let MailWizz retry the subscriber.
        if (!empty($transportError)) {
            throw new Exception(sprintf('CyberMail connection error: %s', $transportError));
        }

        $type    = $this->extractErrorValue($data, ['type', 'code']);
        $message = $this->extractErrorValue($data, ['message', 'detail', 'reason']);

        if ($message === '') {
            $message = 'Unknown CyberMail API error';
        }

        $label = $type !== '' ? $type : (string)$httpCode;

        // Nothing will send until a human fixes this: a bad or revoked key, a
        // key without permission, or a suspended account. Every remaining
        // subscriber would fail the same way.
        if ($httpCode === 401
            || in_array(strtolower($type), self::FATAL_ERROR_TYPES, true)) {
            $this->deactivate(sprintf('[%s] %s', $label, $message));

            throw new Exception(sprintf(
                'CyberMail refused the request and the server has been disabled: [%s] %s',
                $label,
                $message
            ));
        }

        // The campaign's "from" domain is wrong for this server, not the
        // account. By request, this does NOT deactivate the server: every
        // recipient in the offending campaign will still fail (visible in
        // its delivery log), but other campaigns using a verified domain
        // keep sending normally.
        if (in_array(strtolower($type), self::DOMAIN_ERROR_TYPES, true)) {
            throw new Exception(sprintf(
                'CyberMail rejected this message, the "from" domain is not registered or verified: [%s] %s',
                $label,
                $message
            ));
        }

        // Rate limited at the soft, API layer. retry_after is in seconds when
        // supplied. It is looked for both inside the error object and at the
        // top level, since support's own example put it inside "error".
        if ($httpCode === 429 || strcasecmp($type, 'rate_limit_exceeded') === 0) {
            $retryAfter = $this->extractErrorValue($data, ['retry_after', 'retryAfter']);
            $after      = $retryAfter !== '' ? sprintf(' Retry after %ds.', (int)$retryAfter) : '';

            throw new Exception(sprintf('CyberMail rate limit hit, this message will be retried: [%s] %s%s', $label, $message, $after));
        }

        // send_failed (confirmed by support to always arrive as HTTP 500) is
        // one type covering two very different causes, distinguished only by
        // the message text: the HARD, mail-platform-level rate limit, or a
        // recipient that has been suppressed after a spam complaint.
        if (strcasecmp($type, 'send_failed') === 0) {
            if ($this->messageContainsAny($message, self::RATE_LIMIT_MESSAGE_HINTS)) {
                throw new Exception(sprintf('CyberMail hard rate limit hit, this message will be retried: [%s] %s', $label, $message));
            }

            if ($this->messageContainsAny($message, self::SUPPRESSED_MESSAGE_HINTS)) {
                // A suppressed recipient (their FBL/complaint outcome, see the
                // class notes). Skip this subscriber, do not retry: retrying
                // would only repeat the same rejection every time.
                throw new Exception(sprintf('CyberMail: recipient is suppressed, likely a prior complaint: [%s] %s', $label, $message));
            }

            // Neither hint matched. Treat as transient rather than guess.
            throw new Exception(sprintf('CyberMail send failed: [%s] %s', $label, $message));
        }

        // No sending node available. Confirmed transient, retry later.
        if ($httpCode === 503 || strcasecmp($type, 'service_unavailable') === 0) {
            throw new Exception(sprintf('CyberMail has no sending node available, this message will be retried: [%s] %s', $label, $message));
        }

        // Message level problem, for example an invalid recipient address.
        // Skip this subscriber and keep the campaign running.
        if ($httpCode === 400 || $httpCode === 422) {
            throw new Exception(sprintf('CyberMail rejected this message: [%s] %s', $label, $message));
        }

        // internal_error and anything else unrecognised: transient, keep
        // going rather than risk deactivating on an unknown error type.
        throw new Exception(sprintf('CyberMail error: [%s] %s', $label, $message));
    }

    /**
     * Case-insensitive substring match against a set of hints.
     *
     * @param string $message
     * @param array $hints
     *
     * @return bool
     */
    protected function messageContainsAny(string $message, array $hints): bool
    {
        $haystack = strtolower($message);

        foreach ($hints as $hint) {
            if (strpos($haystack, strtolower($hint)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pull the first present key out of an error body, looking inside the
     * "error" object first, then at the top level.
     *
     * @param array $data
     * @param array $keys
     *
     * @return string
     */
    protected function extractErrorValue(array $data, array $keys): string
    {
        $scopes = [];

        if (!empty($data['error']) && is_array($data['error'])) {
            $scopes[] = $data['error'];
        }

        $scopes[] = $data;

        foreach ($scopes as $scope) {
            foreach ($keys as $key) {
                if (isset($scope[$key]) && is_scalar($scope[$key]) && (string)$scope[$key] !== '') {
                    return (string)$scope[$key];
                }
            }
        }

        // The error may also be a plain string.
        if (!empty($data['error']) && is_string($data['error']) && in_array('message', $keys, true)) {
            return (string)$data['error'];
        }

        return '';
    }

    /**
     * Perform a request against the CyberMail API.
     *
     * Returns:
     *  [
     *      'success'  => bool,
     *      'httpCode' => int,
     *      'data'     => array,
     *      'error'    => string, // transport level error only
     *  ]
     *
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @return array
     */
    protected function apiRequest(string $method, string $url, ?array $body = null): array
    {
        $payload = null;
        $headers = [
            'Authorization: Bearer ' . (string)$this->username,
            'Accept: application/json',
        ];

        if ($body !== null) {
            $payload   = (string)json_encode($body);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($payload);
        }

        // Base timeout, extended for large payloads. Test sends were observed
        // taking anywhere from well under a second to about ten, so the base is
        // generous.
        $timeout = 30;
        if ($payload !== null) {
            $timeout += (int)floor(strlen($payload) / 1048576) * 20;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = (string)curl_error($ch);
        curl_close($ch);

        $data = [];
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                $data = ['error' => substr($response, 0, 500)];
            }
        }

        // CyberMail wraps every response in a boolean "success" key. Require
        // it, but tolerate its absence on a 2xx in case the envelope differs.
        $ok = empty($error) && $httpCode >= 200 && $httpCode < 300;
        if ($ok && array_key_exists('success', $data)) {
            $ok = !empty($data['success']);
        }

        return [
            'success'  => $ok,
            'httpCode' => $httpCode,
            'data'     => $data,
            'error'    => $error,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getParamsArray(array $params = []): array
    {
        $params['transport'] = $this->serverType;

        return parent::getParamsArray($params);
    }

    /**
     * @inheritDoc
     */
    public function getFormFieldsDefinition(array $fields = []): array
    {
        return parent::getFormFieldsDefinition(CMap::mergeArray([
            'hostname'                => null,
            'port'                    => null,
            'protocol'                => null,
            'timeout'                 => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ], $fields));
    }

    /**
     * @return void
     */
    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->_initStatus = $this->status;
        $this->hostname    = 'platform.cyberpersons.com';
    }

    /**
     * @return void
     */
    protected function afterFind()
    {
        $this->_initStatus = $this->status;
        parent::afterFind();
    }
}
