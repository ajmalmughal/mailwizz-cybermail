# MailWizz CyberMail delivery server

Free, open-source [MailWizz](https://www.mailwizz.com/) delivery server
extension for [CyberMail](https://platform.cyberpersons.com/) (CyberPanel's
email delivery service).

Sends over HTTP instead of SMTP, which matters if your host blocks outbound
mail ports. Bounces come back through signed webhooks and are processed
automatically.

Requires **MailWizz 2.7.0+**. Installs as a zip. No core files are modified.

---

## Why

Many hosts block outbound SMTP, which leaves MailWizz unable to send at all.
MailWizz ships API-based delivery servers for Mailgun, SendGrid, SparkPost,
Postmark, Mailjet and others, but not CyberMail. This fills that gap.

Everything below was checked against the live CyberMail API and confirmed with
CyberMail support, not just taken from their published documentation — which
differs from the real behaviour in several places. See [Notes on the
docs](#notes-on-the-docs).

## Features

- **HTTP delivery** — no SMTP ports required
- **Correct `from_name` handling** — CyberMail requires `from` to be a bare
  address; the display name is sent as a separate `from_name` field
- **Always sends a text part** — generated from the HTML when MailWizz
  supplies none, matching how MailWizz's own API-based servers behave
- **Header pass-through** — `List-Unsubscribe`, `List-Unsubscribe-Post` and any
  other custom header reach the delivered message unchanged; identity and MIME
  headers are filtered out, since sending them risks a 400 for the whole
  request
- **Signed webhooks** — HMAC-SHA256 verified, event de-duplicated by body hash
- **Accurate attribution** — the API message id is stored and matched back
  from webhooks, scoped to the delivery server that actually sent it, so
  events map to the right campaign and subscriber even when MailWizz rotates
  across several delivery servers
- **Fails safely, without over-reacting** — an invalid or revoked API key
  disables the server (one click to re-enable, no confirmation email); a wrong
  or unverified sending domain fails just that campaign and leaves the server
  running

---

## Install

1. Download `cybermail.zip` from
   [Releases](https://github.com/ajmalmughal/mailwizz-cybermail/releases)
2. MailWizz backend → **Extensions** → **Upload extension** → select the zip
3. Enable it

Or copy the `cybermail/` folder into `apps/extensions/` and enable it in the
UI.

## Set up

### 1. Add and verify your sending domain

Add the domain in your CyberMail dashboard and complete verification (SPF and
DKIM records) before creating the delivery server. An unverified or
unregistered `from` domain fails every send from that campaign with a clear
`domain_not_found` / `domain_not_verified` message in the delivery log — the
server itself is left running, so other campaigns using a verified domain are
unaffected.

### 2. Create the delivery server

**Delivery servers → Create new → CyberMail Email API**

| Field | Value |
|---|---|
| Api key | Your CyberMail API key, needs the `can_send` permission |
| Webhook signing secret | The per-endpoint secret from step 3 |
| From email | An address on a domain verified in your CyberMail account |

Save it, then reopen it — the webhook url only appears once the server has an
id.

### 3. Create the webhook

In your CyberMail dashboard: **Webhooks → Add Webhook**

- **Endpoint URL** — the DSWH url shown on the delivery server page, in the
  form `https://your-mailwizz.com/index.php/dswh/<id>`
- **Events** — leave every box unchecked (this subscribes to all events);
  the ones MailWizz doesn't act on are simply logged and ignored

Copy the signing secret shown when the webhook is created — CyberMail shows it
**once only** — and paste it into the delivery server's "Webhook signing
secret" field.

The dashboard's own "Test" button sends an unsigned test event; the extension
answers it without checking the signature, purely to confirm the endpoint is
reachable.

---

## Headers

Everything is forwarded to CyberMail except identity and MIME headers
(`From`, `To`, `Subject`, `Message-ID`, `Content-Type` and similar), which
CyberMail builds itself and which risk a 400 for the whole request if
duplicated. `List-Unsubscribe`, `List-Unsubscribe-Post`, and any `X-*` header
MailWizz adds all reach the delivered message unchanged.

`tags` are never sent to CyberMail's API — they turn into a visible `X-Tags`
header on the delivered message, which isn't appropriate for a marketing send.

## Attachments are not supported

**CyberMail's REST API does not accept attachments.** Confirmed directly with
CyberMail support: no field exists for them, not even an undocumented one.
Their only path for attachments is a separate SMTP credential on your CyberMail
account, which is a different transport entirely and is not used by this
extension. Campaigns carrying attachments are still delivered, without them,
and a note is written to the delivery log.

## Tracking

CyberMail does not currently rewrite links or add its own open-tracking pixel
— confirmed by support, this is simply not implemented on their side yet.
Campaign HTML passes through unmodified, so MailWizz's own open and click
tracking works normally and is the only tracking you'll see.

## Bounces and complaints

CyberMail runs no bounce mailbox. The `Return-Path` on delivered mail is your
own `from` address; CyberMail's `MessageBounced` webhook is the real bounce
signal, and it's what this extension relies on. `bounce_type: hard` blacklists
the subscriber; `soft` is recorded without blacklisting.

**There is no complaint webhook.** CyberMail confirmed a spam complaint isn't
delivered as an event at all — instead, the *next* send attempt to that
address is rejected with `500 send_failed`, message containing "suppressed".
So a complaint can only be detected after the fact, on a later send, not as it
happens. This extension records that outcome by skipping the affected
recipient, without blacklisting them (a suppression is CyberMail's own
decision, on CyberMail's side, and may not reflect an actual complaint).

Webhook delivery is a **single attempt with no automatic retry** (confirmed by
support). CyberMail disables an endpoint after 10 consecutive failures, so the
processor always answers `200` promptly for anything it has accepted,
including events it deliberately ignores, and `401` only when a request can't
be authenticated.

## When sending stops

The server is **disabled** (not deactivated — see below) when:

- The API key is rejected (`authentication_error`)
- The key lacks permission, is IP-restricted, or the domain is outside its
  allowed list (`forbidden`)
- The account itself is inactive (`account_inactive`)

These are account-wide problems: every remaining subscriber would fail
identically, so continuing would just burn through the list for nothing.

A wrong or unverified **domain** (`domain_not_found`, `domain_not_verified`)
does **not** stop the server — only that campaign's sends fail, each one
logged clearly, while the server stays active for correctly configured
campaigns.

### Why "disable", not "deactivate"

MailWizz has two distinct statuses here. Setting a server **Inactive**
requires a confirmation email and a validation link to bring back. Setting it
**Disabled** lets you re-enable it with a single click in the UI, no email
round trip. Both statuses are treated identically by MailWizz's own campaign
sending code — neither lets the next message through until you act — so
`Disabled` gives the same protection with a faster recovery path. This
extension uses `Disabled` on every automatic stop.

The reason is written to the campaign delivery log, to
`apps/common/runtime/cybermail-errors.log`, to the application log at ERROR
level, and to the customer's message inbox in the MailWizz UI.

## Rate limits

CyberMail enforces two separate layers, confirmed by support:

- A **soft**, best-effort per-minute cap at the API layer, which returns
  `429 rate_limit_exceeded` with a `retry_after` value
- A **hard** per-second/hour/day cap at the mail platform itself, which
  surfaces as `500 send_failed` with rate-limit wording in the message, not
  429

Both are treated as retryable by this extension. Neither is hardcoded:
MailWizz's own per-server hourly/daily/monthly quota fields are what should be
set to match your CyberMail plan, since those limits change when you upgrade
and the extension has no way to know your current plan from the API alone.

---

## Notes on the docs

CyberMail's public API documentation, at the time this was written, differs
from the live behaviour in several confirmed ways:

- Webhook event names are `MessageSent`, `MessageDelivered`,
  `MessageDeliveryFailed`, `MessageBounced` — not the lowercase names the docs
  show
- The bounce reason field is `bounce_message`, not the documented
  `error_message`
- `from_name` (undocumented) is the officially supported way to set a display
  name; `from` must be a bare address
- The DKIM signature covers only `From`, `To`, `Subject` — not `Date` or
  `Message-ID`
- A `403 forbidden` error type exists alongside `domain_not_found`,
  `domain_not_verified` and `account_inactive`, and is not documented
- `500 send_failed` covers two different causes (a hard rate limit, or a
  suppressed recipient), distinguished only by the message text, and is not
  documented at all

## License

MIT. See [LICENSE](LICENSE).
