# Changelog

All notable changes to this project are documented here.

## [1.0.0] - 2026-09-25

First release.

### Added
- CyberMail Email API delivery server type for MailWizz, registered through
  filters with no core file changes
- Sending via `POST https://platform.cyberpersons.com/email/v1/send` with
  bearer authentication
- `from_name` sent as a separate field (undocumented but confirmed by
  CyberMail support as the officially supported mechanism); `from` is always
  sent as a bare address, since CyberMail rejects `Name <address>` with 400
- Always sends a text part, generated from the HTML via
  `CampaignHelper::htmlToText()` when MailWizz supplies none — matches the
  behaviour of MailWizz's own API-based delivery servers (e.g. Mailjet)
- Header pass-through with an identity/MIME header block list, so
  `List-Unsubscribe`, `List-Unsubscribe-Post` and custom `X-*` headers reach
  the delivered message
- `tags` are never sent to the API, since CyberMail turns them into a visible
  `X-Tags` header
- Signed webhook processing for the confirmed real event names
  (`MessageBounced`, `MessageDeliveryFailed`); `MessageSent`, `MessageDelivered`,
  `MessageLoaded` and `MessageLinkClicked` are accepted and ignored
- HMAC-SHA256 signature verification over the raw webhook body, keyed with the
  signing secret as a plain string
- Event de-duplication by request body hash, since CyberMail sends no event id
- Explicit hard/soft bounce handling from CyberMail's `bounce_type`; hard
  bounces blacklist the subscriber
- Bounce attribution scoped to the delivery server that sent the message, so
  the extension behaves correctly when MailWizz rotates across several
  delivery servers, and when several CyberMail servers share one API key
- Structured error handling for CyberMail's
  `{"success":false,"error":{"type":...,"message":...}}` envelope, covering
  every confirmed error type: `invalid_request`, `authentication_error`,
  `forbidden`, `domain_not_found`, `domain_not_verified`, `account_inactive`,
  `rate_limit_exceeded`, `service_unavailable`, `send_failed`,
  `internal_error`
- `send_failed` (always HTTP 500) is disambiguated by message text into a
  retryable hard rate limit or a suppressed-recipient skip, since CyberMail
  uses one error type for both and support confirmed there is no other way to
  tell them apart
- Server is disabled (`DeliveryServer::disable()`, one-click re-enable — not
  deactivated to `STATUS_INACTIVE`, which requires a confirmation email) on
  account-wide failures: `authentication_error`, `forbidden`,
  `account_inactive`
- A wrong or unverified `from` domain (`domain_not_found`,
  `domain_not_verified`) does **not** disable the server — only that
  campaign's sends fail, logged clearly, while the server stays active for
  correctly configured campaigns
- The reason for any automatic disable is written to the campaign delivery
  log, to a dedicated `cybermail-errors.log`, to the application log at ERROR
  level, and to the customer's message inbox in the MailWizz UI

### Notes
- Attachments are not sent. Confirmed directly with CyberMail support: their
  REST API has no attachment field at all, documented or otherwise. Their only
  attachment path is a separate SMTP credential, a different transport not
  used by this extension. Each omission is logged rather than silently
  dropped.
- CyberMail does not currently do open/click tracking of its own (confirmed by
  support, not yet implemented on their side), so campaign HTML passes through
  unmodified and MailWizz's own tracking is unaffected.
- There is no complaint/FBL webhook event. Confirmed by support: a complaint
  instead suppresses the recipient, surfacing only on a later send attempt as
  `500 send_failed` with "suppressed" in the message. The extension detects
  this and skips the recipient without blacklisting them.
- CyberMail webhook delivery is a single attempt with no automatic retry, and
  the endpoint is disabled after 10 consecutive failures (confirmed by
  support). The processor always answers 200 promptly for anything it accepts.
- Two rate-limit layers exist: a soft per-minute cap (`429
  rate_limit_exceeded`) and a hard per-second/hour/day cap enforced at the
  mail platform itself, which surfaces as `500 send_failed` with rate-limit
  wording rather than 429. Neither limit is hardcoded in the extension —
  MailWizz's own per-server quota fields should be set to match your plan.
- Verified end to end against the live CyberMail API and a real MailWizz
  campaign: sending, `from_name`, `reply_to`, custom headers, the generated
  text part, `List-Unsubscribe`/`List-Unsubscribe-Post` delivery, webhook
  registration, signature verification, event parsing, message id
  correlation, hard-bounce attribution to a campaign subscriber, and automatic
  blacklisting.
