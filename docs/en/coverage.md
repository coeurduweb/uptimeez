# Everything Uptimer watches

**The complete list, layer by layer, with what each check catches and what it costs.**

[← Documentation](README.md) · [Version française](../fr/etendue.md)

---

## On one page

| Layer | Checks | Network cost |
|---|---|---|
| Availability | HTTP code, detailed timings, redirects, retries | The page request |
| Layout | 9 signals over CSS, scripts and fonts, plus the silhouette | The page's resources, at most every 15 min |
| Data | 45 signatures, CMS probe, proof string, forbidden string | No extra call, except the CMS probe |
| Speed | Response time, render-blocking files, top-of-page image | One HEAD request on an image |
| Deadlines | Certificate, domain, published advisories | One pass a day, cached |
| Silence | Dead-man heartbeat | None: your script does the calling |

Everything is on by default for a page monitor, and each check can be switched off individually.

---

## 1. Does it answer

**HTTP code against an expected range.** `200-299` by default, but `200,301,404` is accepted: a maintenance page
that must return 503 is monitored just as well as a normal one.

**Detailed timings, on every measurement.** DNS, connect, TLS handshake, first byte, total. That breakdown is what
tells "the server is slow" apart from "DNS resolution takes two seconds".

**Redirect chain.** Followed to the target, with loop detection. If a site redirects permanently (`http` to
`https`, adding `www`), Uptimer says so and offers to align the monitor on the target in one click.

**Retries before alerting.** A two-second network hiccup is not an outage. A monitor only goes down after N
consecutive failures, N being per-monitor.

**The address actually contacted.** The IP is recorded on every measurement. When ten sites fail together on one
IP, Uptimer sends **one** grouped alert saying the problem is very probably the hosting.

**Maintenance windows.** `02:00-04:00`, `mon-fri 02:00-04:00` or `sat,sun 01:00-06:00`: nothing alerts during
those ranges.

---

## 2. Is the page right

This is the part no uptime tool does without you writing code. The premise: a site can answer `200 OK` in 180 ms
and display nothing but a wall of raw text.

**Nine crossed signals**, over every stylesheet, script and font declared in the page:

| Signal | What it catches |
|---|---|
| Resource availability | The classic post-deployment 404 |
| MIME type and `nosniff` | The server returns HTML or a PHP trace instead of CSS |
| Mixed content | An `http` resource on an `https` page, silently blocked by the browser |
| CSP `style-src` | A security policy that blocks your own stylesheets |
| SRI `integrity` | A stale digest: the browser refuses a perfectly valid file |
| Weight against the learned baseline | Half the CSS gone without a single 404 |
| Class coverage | Classes in the HTML with no matching rule, tolerant of Tailwind escapes |
| Media queries | The responsive layout vanished |
| Blocks awaiting an animation | Content hidden by a reveal script that never loaded: an *invisible* page |

**Reconstructed console messages.** Uptimer rewrites what the browser would have printed:
`net::ERR_ABORTED 404`, `Refused to apply style from …`, `Mixed Content: …`,
`Failed to find a valid digest in the 'integrity' attribute …`. The ticket you hand a developer already contains
the evidence.

**Before/after silhouette.** The page is rebuilt as a wireframe from the HTML and CSS actually received, and
compared with the reference silhouette. An image is understood without reading, and it is the thing you show a
client. It is always labelled a reconstruction, never a screenshot. See [Detection](detection.md).

**A learned baseline, not a configured one.** The fingerprint is taken on a healthy state. So an intentional
redesign does not wake anyone at 3 a.m., and one button relearns the reference when the change is wanted.

**Content changes.** A fingerprint of the visible text: catches a publication going live, and a defaced page.
Chatty on a site that publishes often, so it is off by default.

**Watched word.** A text whose appearance or disappearance matters: "Out of stock", "Fatal error", the name of a
product that must stay online.

**Forgotten `noindex`.** The `meta robots` tag and the `X-Robots-Tag` header. It is the silent SEO killer after a
release, and nobody notices for weeks.

---

## 3. Does the data answer

**Around 45 signatures**, grouped by engine and framework: WordPress, MySQL and MariaDB, PDO, mysqli, Doctrine,
Laravel, PrestaShop, Joomla, Drupal, SQLite, PostgreSQL. They cover connection refused, a corrupt table, a table
missing from the engine, a disk quota reached, a damaged index, fatal errors, and memory or execution-time
exhaustion.

**A CMS probe that really traverses the database.** On WordPress, the REST API rather than the homepage: a cached
homepage can render perfectly while the database is down.

**A proof string, derived from the site.** A piece of text that can only come from the database. In order of
preference: the footer copyright, `og:site_name`, the page title, the first nav item, the H1. The chosen string is
verified, stripped of generic wording, and never inferred from an error page. Its disappearance while the page
still answers `200` means the data layer is gone.

**Forbidden string.** The reverse: a text that must never appear. It is what the inverted keywords of other tools
become when you import from them.

**"Error showing" is not "site down".** A signature found in a page is not enough to conclude: a blog post
explaining how to fix "Error establishing a database connection" contains the phrase without being broken, and for
an agency whose clients include hosts and developers that is the guaranteed 3 a.m. false alarm. So at least one
other sign is required, and one is enough: the server answers 5xx, or the page is short — a real WordPress error
page weighs a few hundred bytes — or the proof string has vanished. With none of the three, the verdict is
**degraded** and says what it sees: a technical error is showing on a page that works. That is a real defect,
visible to visitors, but it is not an outage.

**What could not be read proves nothing.** Reading stops at 3 MB of HTML, so as not to exhaust a shared host's
memory. Past that the end of the page is not read: a proof string sitting in the footer can no longer be found, and
its absence says nothing. The verdict is then "page too large to be verified in full", never "the database is
down".

---

## 4. Is it fast for visitors

**Server response time**, measured on every check. It is the floor for everything else: nothing is painted before
it. The target is 800 ms.

**Self-tuning slowness threshold.** Set from the monitor's measured p95, multiplied by 1.8, with a 20 % deadband
and six hours between two adjustments. A naturally slow site therefore does not cry wolf, and a site that really
degrades is caught. Every adjustment is written down.

**What blocks the first paint**, with the exact weight of each file: stylesheets in the head, scripts without
`defer` or `async`. `media="print"` is correctly excluded.

**The top-of-page image**, its real weight from a single HEAD request, and two very common defects: the image set
to `loading="lazy"` when it is exactly what the visitor is waiting for, and the image over 250 kB.

**The causes of layout shift**: images without `width` or `height`, fonts without `font-display`.

**Third-party scripts** loaded in the head, counted per domain.

**The three official metrics** (LCP, INP, CLS) on your real visitors, with a free Chrome UX Report key. Without a
key, no figure is invented. See [Perceived speed](speed.md).

---

## 5. Will it break soon

**TLS certificate, in two passes.** A permissive read to establish the facts, a strict browser-like validation for
the verdict. Expiry, chain, authority, domain match, with configurable notice. Plus the case everyone forgets: a
certificate **not valid yet**, which browsers refuse exactly the way they refuse an expired one. That is a skewed
server clock, or a certificate issued ahead of time and deployed too early; the verdict says so instead of handing
back OpenSSL's raw message.

**Domain expiry**, over RDAP, once a day. An expired domain kills the site *and* the e-mail, and can be bought by
someone else.

**Published vulnerabilities on the detected versions.** The software inventory is read from the HTML already
fetched, then crossed with OSV.dev and api.wordpress.org. "Published vulnerability" and "behind latest" are never
conflated. See [Security watch](security-watch.md).

**Dead-man heartbeat.** Your backup, your export, your nightly job calls Uptimer when it finishes. **Silence**
raises the alert, the one failure no HTTP request can ever see.

---

## The five kinds of monitor

| Kind | What it checks | Own settings |
|---|---|---|
| **Page** | An HTML page, with every check above | Resources, database, certificate, noindex, content |
| **JSON API** | An API endpoint | Field path, expected value, headers, body, method |
| **Asset** | A file that must stay reachable and unchanged | Content fingerprint |
| **Keyword** | A text that must be there, or must never be there | Expected string, forbidden string |
| **Heartbeat** | A job that must run | Expected frequency, slack |

A main monitor carries the site's state; the site's other pages are grouped with it.

---

## What Uptimer deliberately does not watch

A tool that claims to do everything does nothing well. What is out of scope, and why:

- **TCP ports, ICMP ping, DNS, SMTP.** Uptimer monitors over HTTP. An open port does not say a site works, and an
  HTTP monitor does not replace a port test: claiming otherwise would misrepresent what is being checked.
- **Server metrics** (load, disk, memory). That needs an agent on the machine. Zabbix does it very well.
- **Scripted journeys** (log in, add to basket, pay). That needs a browser. Checkly does it very well.
- **Application tracing** inside your code. New Relic does it very well.
- **Full SEO auditing.** Uptimer reports a forgotten `noindex` because it is an operational accident, not because
  it audits SEO.

Uptimer does one thing: **keep other people's websites alive without a full-time human watching dashboards.**

---

[← Documentation](README.md) · [Detection](detection.md) · [Monitors](monitors.md) · [Speed](speed.md) · [Security watch](security-watch.md)
