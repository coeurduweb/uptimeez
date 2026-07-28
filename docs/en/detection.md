# How detection works

[← Monitors](monitors.md) · [Documentation](README.md) · [Alerts →](alerts.md)

This page explains what Uptimer actually looks at. It matters for two reasons: you need to trust a verdict before
you act on it, and you need to know why a false alarm is a bug we want to hear about.

---

## The 23 causes

Every verdict resolves to one cause, and every cause carries a title, an explanation and a remedy, in your
language.

| Code | Cause |
|---|---|
| `DNS` | The domain name no longer resolves |
| `CONNECT`, `CONNECT_RESET` | The server refuses the connection |
| `TIMEOUT` | The server does not answer in time |
| `SSL_EXPIRED` | The certificate has expired |
| `SSL_INVALID`, `SSL_HANDSHAKE` | Browsers reject the certificate |
| `SSL_SOON` | The certificate expires soon |
| `HTTP_5XX` | The server returns an error |
| `HTTP_404` | The page is gone |
| `HTTP_403` | Access is forbidden |
| `HTTP_401` | Authentication is required |
| `HTTP_429` | Too many requests |
| `HTTP_3XX` | Unexpected redirect |
| `REDIRECT_LOOP` | Redirect loop |
| `DB_DOWN` | The database no longer answers |
| `APP_ERROR` | PHP application error |
| `CSS_BROKEN` | The page layout is broken |
| `CSS_DEGRADED` | Rendering resources partly degraded |
| `STRING_MISSING` | The proof string has vanished |
| `STRING_FORBIDDEN` | A forbidden string has appeared |
| `JSON_INVALID`, `JSON_PATH`, `JSON_VALUE` | The API does not return the expected answer |
| `NOINDEX` | The page is set to `noindex` |
| `SLOW` | Response time exceeds your threshold |
| `HEARTBEAT_LATE` | The expected signal never arrived |

---

## Broken layout: the nine signals

A page that answers `200` can still be unusable. Uptimer fetches the HTML, extracts every stylesheet, script and
font, fetches them too (capped at six resources per pass to stay polite), and crosses nine signals.

### 1. Availability

Each resource is fetched. A `404`, a `403` or a timeout on a stylesheet is decisive: the browser would have
rendered the page unstyled. This is the classic post-deployment failure : a cache path that no longer exists, a
build hash that changed, a file not uploaded.

### 2. MIME type and `nosniff`

A server that returns `text/html` for a `.css` file is usually returning an error page or a PHP trace. With
`X-Content-Type-Options: nosniff`, which most hardened servers now send, the browser refuses the file outright.
Uptimer reports it as blocked, because that is what happens in reality.

### 3. Mixed content

An `http://` asset referenced from an `https://` page is blocked silently by every modern browser. No error
appears in the HTML, nothing in the server log. Only the visitor sees a broken page.

### 4. Content Security Policy

If the page's CSP has a `style-src` that excludes the origin actually serving your stylesheet, the browser
refuses it. This happens after a security hardening pass that nobody connected to the CSS.

### 5. Subresource integrity

An `integrity` attribute whose hash no longer matches the file makes the browser reject a perfectly valid,
perfectly available stylesheet. It happens whenever a file is regenerated without updating the hash.

### 6. Volume against the learned baseline

Uptimer remembers what healthy looks like: total stylesheet weight and number of CSS rules. A drop beyond the
tolerated percentage (35 % by default) means the CSS was replaced by something much smaller : a truncated build,
a cache mid-purge, an over-eager minifier.

### 7. Class coverage

It collects the classes used in the HTML and checks how many find a matching rule in the loaded CSS. A page whose
classes have almost no rules is a page with no styling, even if every file returned `200`. Tailwind's escaped
class names (`md:flex`, `w-1/2`) are handled, so utility-first sites do not trigger it.

### 8. Media queries

No media query left in the whole CSS means the responsive layout is gone. Desktop may look fine while every phone
visitor gets a broken page.

### 9. Content awaiting an animation

Modern page builders hide blocks (`opacity: 0`) and reveal them with a script. If that script fails, the page
answers `200`, the CSS is fine, and the content is *invisible*. Uptimer counts blocks left waiting for a reveal
and flags it : a failure mode that looks perfect from every other angle.

### The silhouette: showing instead of describing

A number does not settle an argument with a client. An image does.

So on every audit, Uptimer reconstructs a **silhouette** of the page: the block
structure read from the HTML, laid out according to what the CSS actually loaded
allows. Headings, paragraphs, images, buttons, columns. It stores the silhouette
of a healthy state as the reference, and compares the current one against it.

When a stylesheet fails, the silhouette changes exactly as the page changes: no
more centred container, no more columns, everything stacked at full width,
images enormous. That is precisely what the visitor is looking at.

The monitor page shows both side by side with the measured difference, and any
site above 20 % difference appears in the client report under "What the visitor
sees".

**It is not a screenshot, and the interface says so.** Uptimer runs no browser:
that is what lets it check hundreds of sites from shared hosting. The silhouette
is a functional reconstruction, and for this purpose it is enough.

The difference is measured on five traits a visitor perceives: is the content
still held in a centred container, are there still columns, has the page grown
much taller, is the variety of block types still there, does everything now span
the full width. Above 35 %, a visitor sees a different page.

**A security note, because it matters here.** The silhouette is an SVG injected
directly into the page. Nothing the monitored site controls ever reaches it: the
renderer emits only numbers and a fixed palette, no text, no class name, no
attribute from the site. The test suite verifies it with deliberately hostile
HTML.

### What you get out of it

Beyond the verdict, the monitor page reconstructs **what the browser console would have printed**:

```
net::ERR_ABORTED 404 (Not Found)   …/wp-content/cache/min/1/absent.css
Refused to apply style from '…/theme.css' because its MIME type ('text/html') is not a
  supported stylesheet MIME type, and strict MIME checking is enabled.
Mixed Content: The page at 'https://…' was loaded over HTTPS, but requested an insecure
  stylesheet 'http://…'. This request has been blocked.
```

That block is copy-pasteable into a ticket, and it is why a developer believes you.

### Why it does not cry wolf

- The baseline is **learned from healthy states**, not configured.
- A verdict needs a **decisive signal** (a missing or blocked stylesheet) or **several converging weak signals**.
- Third-party resources are weighted differently from your own: a slow font CDN degrades, it does not break.
- An intentional redesign is one button away from being the new normal (*Relearn the reference*).
- When the audit does not run on a pass, the previous verdict is carried forward rather than reset : no flapping
  between "broken" and "fine".

---

## Database down behind a 200

Three independent signals, because any one of them alone can be fooled.

**1. Error signatures.** Around 45 patterns covering WordPress ("Error establishing a database connection"),
Laravel, Doctrine, PDO, Symfony and raw MySQL ("Too many connections", "Access denied for user", "MySQL server
has gone away"), plus PHP-level failures: memory exhausted, uncaught exception, disk full, SQLite locked, Redis
connection failed.

**2. The proof string.** Text that can only come from the database. If it disappears while the page still answers
`200`, the data layer is gone. This catches the polite failure : the CMS that serves a cached shell with empty
content.

**3. A CMS probe that really touches the database.** On WordPress, `/wp-json/` traverses the database, unlike a
fully cached homepage. Uptimer adds that probe automatically when it detects WordPress.

---

## Certificates: two passes

A single TLS read cannot answer both questions you need answered. Uptimer does two.

**Permissive pass**, connects while accepting anything, reads the certificate and extracts the facts: subject,
issuer, expiry, alternative names. This works even on an expired or self-signed certificate, which is exactly when
you need the details.

**Strict pass** : connects the way a browser does, verifying peer and hostname. Its only job is the verdict: would
a visitor see a warning screen?

Together they distinguish "expires in 3 days" from "expired yesterday" from "valid but does not cover this
domain" from "unknown authority" : four situations with four different remedies.

---

## Response time and the self-tuning threshold

Every check records DNS, connect, TLS, first byte and total time.

A fixed threshold is wrong twice: too low for a naturally heavy site (constant false alarms), too high for a fast
site (a real degradation goes unnoticed). So the threshold is derived from **the site's own p95 × 1.8**, floored
at 1.2 s and capped at 20 s, with:

- a minimum of 20 measurements before any adjustment;
- a 6-hour cooldown between adjustments;
- a ±20 % deadband, so an insignificant change never rewrites the setting.

Every adjustment is written to the decisions journal with its reason. And the moment you type a value by hand,
automatic tuning stops for that monitor.

---

## Domain expiry

A daily RDAP query per registrable domain. RDAP is the successor to WHOIS and returns structured JSON, so there is
nothing to scrape. An expiring domain shows up in *Coming up* 45 days ahead, early enough to matter and late enough
not to be noise.

---

## What Uptimer deliberately does not do

- **It does not run a browser.** No JavaScript execution, no rendering. That is what keeps it able to check
  hundreds of sites from shared hosting. The nine signals are how it reaches a rendering verdict without a
  rendering engine.
- **It does not test user journeys.** No form filling, no login flows, no shopping-cart scripts. Use Checkly for
  that; it is genuinely good at it.
- **It does not monitor servers.** No CPU, no RAM, no disk on your machines. Zabbix does that.
