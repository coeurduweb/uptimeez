<div align="center">

# Uptimer

### Uptime monitoring that tells you **what to do**, not just what broke.

**Self-hosted website monitoring for people who run other people's websites.**
It catches broken layouts, dead databases hiding behind an HTTP 200, expiring certificates and silent cron
jobs — then hands you a to-do list with the fix one click away.

Zero dependencies · No Docker · Runs on plain shared hosting · SQLite or MySQL · 10 languages

[Why Uptimer](#why-another-uptime-monitor) ·
[Screenshots](#see-it) ·
[What it detects](#what-it-actually-detects) ·
[Comparison](#how-it-compares) ·
[Install in 60 seconds](#install-in-60-seconds) ·
[Documentation](docs/en/README.md) ·
[Version française](README.fr.md)

<img src="docs/img/tour.gif" alt="Uptimer in action: the daily to-do list, contextual help, the command palette, one-click fixes and the wall view" width="820">

</div>

---

## Why another uptime monitor?

Every monitoring tool on the market answers the same question: **is the site up?**

That question stopped being interesting a long time ago. A site can answer `200 OK` in 180 ms and still be
completely broken: the stylesheet 404s after a deployment and visitors get raw HTML; the database is down and
WordPress serves a cheerful error page with a perfect status code; someone left `noindex` on after a release
and Google is quietly dropping the site.

Uptimer was built around three findings from running an agency portfolio on the alternatives:

| What goes wrong with the others | What Uptimer does instead |
|---|---|
| **Setup is a tax.** Twenty screens and forty fields before you have monitored anything at all. | You paste a list of domains. It detects the CMS, picks the pages worth following, infers the string that proves the database answers, tunes thresholds from measured p95 — and shows a **preview before creating anything**. |
| **Alerts become noise.** One server goes down, forty e-mails arrive. After a week nobody reads them. | Failures sharing an IP become **one grouped alert**. Thresholds tune themselves, so a naturally slow site never cries wolf. Quiet hours, maintenance windows, acknowledgement, retries before alerting. |
| **Dashboards show states, not actions.** Green and red dots; you still have to work out what to do. | The home screen is a **to-do list**: cause, why it matters, what to do, the evidence — and the buttons that do it without leaving the page. Every action is undoable. |

> The others show you **states**. Uptimer gives you **a list of things to do**, and guesses everything else.

---

## See it

<table>
<tr>
<td width="50%" valign="top">

**The day starts here.** One card per problem, most urgent first: cause, consequence, remedy, and the buttons
that apply it in place.

<img src="docs/img/today.png" alt="Uptimer home screen: a to-do list of sites needing attention, each with its cause, explanation, remedy and inline action buttons">

</td>
<td width="50%" valign="top">

**The wall view**, for the screen in the office. Green, orange, red. Sites in trouble float to the top — never
below the fold.

<img src="docs/img/wall.png" alt="Uptimer wall view: colour-coded cards for every monitored site with uptime, response time and a 24-hour sparkline">

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Broken layout, explained.** Not "CSS looks odd" — the exact file, the exact cause, and the message the
browser console would have printed.

<img src="docs/img/css-broken.png" alt="Page resources panel showing the failing stylesheet, its HTTP status, the cause, and reconstructed browser console errors">

</td>
<td width="50%" valign="top">

**Nothing hidden, nothing imposed.** One switch moves the whole interface between *Simple* — only what you can
act on — and *Full* — every setting, every measurement.

<img src="docs/img/detail-simple.png" alt="Monitor detail page in simple mode, showing only actionable information">

</td>
</tr>
<tr>
<td width="50%" valign="top">

**Ctrl / ⌘ + K.** Any site, any screen, any action. Accent- and case-insensitive in every language: type
`munchen`, find `München`.

<img src="docs/img/palette.png" alt="Command palette open, searching monitors and screens by name">

</td>
<td width="50%" valign="top">

**A report you can send to a client.** Availability, outages, response times, day-by-day strip. Print, or save
as PDF.

<img src="docs/img/report.png" alt="Printable client availability report with uptime figures, day strip, response-time chart and incident table">

</td>
</tr>
</table>

<details>
<summary><b>More screenshots</b> — dark theme, mobile, other languages, import preview, settings</summary>
<br>

| Dark theme | On a phone |
|---|---|
| <img src="docs/img/today-dark.png" alt="Uptimer home screen in dark theme"> | <img src="docs/img/mobile-today.png" alt="Uptimer home screen on a phone" width="300"> |

| English (the default) | Arabic (right-to-left) |
|---|---|
| <img src="docs/img/today-en.png" alt="Uptimer interface in English"> | <img src="docs/img/today-ar.png" alt="Uptimer interface in Arabic, laid out right to left"> |

**Import: a preview before anything exists.** Paste domains, a spreadsheet, or a client e-mail — Uptimer pulls
the addresses out of it and shows exactly what it is about to do.

<img src="docs/img/import-preview.png" alt="Import preview table listing each site, its check rate, tracked pages and inferred proof string before creation">

**Settings, folded away.** Everything has a sane default; the accordions stay shut until you need them.

<img src="docs/img/settings.png" alt="Uptimer settings screen with collapsed accordions for cron, alerts, defaults and access">

</details>

---

> **About the screenshots.** They come from the bundled demo dataset
> (`php bin/demo.php`). The site names are real and recognisable on purpose — a screenshot should mean something
> at a glance. **Every measurement is fictional**, the interface says so permanently in demo mode, and the four
> failures are deliberately placed on staging subdomains that do not exist (`staging.`, `preprod.`, `beta.`,
> `recette.`). Nothing here says anything about the reliability of any real service.

---

## What it actually detects

Most monitors check a status code and a keyword. Here is what Uptimer watches, and why each one matters.

### 🎨 Broken layout — the one nobody else does out of the box

A deployment goes wrong, the minified stylesheet 404s, and your client's site looks like a 1994 text file.
Status code: `200`. Response time: excellent. Every uptime monitor on the market reports that the site is fine.

Uptimer crosses **nine independent signals** on every HTML page it checks:

| Signal | What it catches |
|---|---|
| Availability of every stylesheet, script and font | The classic post-deployment 404 |
| MIME type + `nosniff` | Server returns HTML or a PHP trace instead of CSS |
| Mixed content | An HTTP asset on an HTTPS page — silently blocked by the browser |
| CSP `style-src` | A policy change that blocks your own stylesheet |
| SRI `integrity` | A stale hash: the browser refuses a perfectly valid file |
| Volume vs learned baseline | Half the CSS gone without a single 404 |
| Class coverage | Classes in the HTML with no matching CSS rule (tolerant of Tailwind escapes) |
| Media queries | The responsive layout has disappeared |
| Content awaiting animation | Blocks hidden by a reveal script that never loaded — an *invisible* page |

Then it does something no other tool does: it **reconstructs the messages the browser console would have
printed** — `net::ERR_ABORTED`, `Refused to apply style from …`, `Mixed Content: …`, `Failed to find a valid
digest …` — so the ticket you hand a developer already contains the evidence.

The baseline is *learned* from healthy states, so an intentional redesign does not page you at 3 a.m. — and
when the design does change on purpose, one button relearns it.

### 🗄️ Database down behind a perfect 200

WordPress, Laravel, Doctrine, PDO and Symfony each have a house style for database failures — and all of them
happily return `200 OK`. Uptimer carries **≈45 error signatures**, cross-checks a CMS probe that really
traverses the database (the WordPress REST API, not the cached homepage), and watches the **proof string**: a
piece of text that can only come from the database, such as the footer copyright.

That string is **derived automatically** — footer copyright → `og:site_name` → page title → first nav item →
H1 — and never taken from an error page. If it vanishes while the page still answers 200, the data layer is
gone and you know within one check.

### 🔒 Certificates, domains, and everything else

| | |
|---|---|
| **TLS certificate** | Two-pass inspection: a permissive read for the facts, a strict browser-like validation for the verdict. Expiry, chain, authority, domain match — with warning before expiry. |
| **Domain expiry** | Daily RDAP check. An expired domain kills the site *and* the e-mail, and can be bought by someone else. |
| **Forgotten `noindex`** | The silent SEO killer after a release. Nobody notices for weeks. |
| **Content changes** | A fingerprint of the visible text: catches a publication going live, and a defaced page. |
| **JSON APIs** | Field path, expected value, custom headers, request body, any method. |
| **Silent cron jobs** | A dead-man heartbeat: your backup script calls Uptimer when it finishes. **Silence raises the alert** — the one failure no HTTP request can ever see. |
| **Response time** | DNS, connect, TLS, first byte, total. Threshold tuned from the site's own measured p95, not a round number. |
| **Grouped outages** | Ten sites failing on one IP is *one* incident, not ten alerts. |

---

## How it compares

Feature comparison against the tools people actually evaluate. This reflects **out-of-the-box behaviour on
standard plans, as of July 2026** — no scripting, no plug-ins, no add-ons. Found a mistake? Open a pull
request; the table lives in this file.

| | **Uptimer** | UptimeRobot | Checkly | Site24x7 | Uptime Kuma | Zabbix | New Relic |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| Broken layout / CSS detection | ✅ automatic | ❌ | ⚠️ write a browser script | ⚠️ defacement only | ❌ | ❌ | ⚠️ write a synthetic script |
| Browser-console errors reconstructed | ✅ | ❌ | ⚠️ in script logs | ❌ | ❌ | ❌ | ⚠️ in script logs |
| Database down behind a 200 | ✅ signatures + proof string | ⚠️ manual keyword | ⚠️ manual assertion | ⚠️ manual keyword | ⚠️ manual keyword | ⚠️ build it yourself | ⚠️ manual assertion |
| Proof string inferred automatically | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Forgotten `noindex` alert | ✅ | ❌ | ⚠️ script | ❌ | ❌ | ❌ | ⚠️ script |
| Bulk add with CMS detection and auto-setup | ✅ paste anything | ⚠️ CSV, no detection | ❌ code-first | ⚠️ CSV | ❌ one by one | ❌ | ❌ |
| Preview before monitors are created | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Self-tuning slowness threshold | ✅ from p95 | ❌ fixed | ❌ fixed | ❌ fixed | ❌ fixed | ⚠️ build it yourself | ⚠️ baselines, paid tiers |
| Journal of the tool's own decisions | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Home screen is a to-do list with fixes | ✅ | ❌ dashboard | ❌ dashboard | ❌ dashboard | ❌ dashboard | ❌ dashboard | ❌ dashboard |
| Undo on every action | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Outages grouped by server IP | ✅ automatic | ❌ | ❌ | ⚠️ dependency config | ❌ | ✅ topology | ⚠️ config |
| Dead-man heartbeat (cron, backups) | ✅ | ✅ | ⚠️ | ✅ | ✅ push | ✅ | ⚠️ |
| Printable client report | ✅ built in | ⚠️ paid plans | ❌ | ✅ | ❌ | ⚠️ | ✅ |
| Public status page | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ | ⚠️ |
| Simple / Full interface switch | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ |
| Interface languages | **10 + RTL** | 1 | 1 | several | many (community) | several | several |
| Runs on plain shared hosting | ✅ PHP only | — SaaS | — SaaS | — SaaS | ❌ Node/Docker | ❌ server | — SaaS |
| Dependencies to install | **none** | — | Node + browsers | — | Node or Docker | server + DB + agent | agent |
| Your data stays on your server | ✅ | ❌ | ❌ | ❌ | ✅ | ✅ | ❌ |
| Cost for 40 sites | **free** | paid tier | paid tier | paid tier | free | free | paid tier |

**Where the others are genuinely better.** Checkly for scripted end-to-end journeys in CI; Zabbix for
infrastructure metrics on servers you own; New Relic for application tracing inside your code; Site24x7 for
breadth if you want a single vendor for everything; SiteGuru for SEO auditing, which is a different job
entirely. Uptimer does not try to be any of those. It does one thing: **keep other people's websites alive
without a full-time human watching dashboards.**

---

## Install in 60 seconds

**Requirements:** PHP 8.1 or newer with `curl`, `pdo_sqlite` (or `pdo_mysql`) and `json`. That is the whole
list. No Composer, no Node, no Docker, no build step.

```bash
# 1. Put the files where your web server can serve them
git clone https://github.com/loran750/uptimer.git
cd uptimer

# 2. Open install.php in a browser and choose a password.
#    On shared hosting: upload by FTP, then visit https://yourdomain.com/uptimer/install.php

# 3. One cron entry, every minute — whatever your check intervals are
* * * * * /usr/bin/php /path/to/uptimer/cron.php >/dev/null 2>&1
```

Uptimer picks the monitors that are due itself, so a single per-minute pass covers every interval from 30
seconds to a day. No crontab access? The settings screen hands you a URL to call from any external scheduler.

**Want to look around first? There is a demo mode.** It builds a 13-site portfolio on recognisable domains,
30 days of history, and the four flagship failures — broken layout, dead database, slowdown, forgotten
`noindex`:

```bash
php bin/demo.php                  # password: demo1234
php -S 127.0.0.1:8390 -t .        # then open http://127.0.0.1:8390/
php bin/demo.php --purge          # removes it, leaves no trace
```

Demo mode is not a different build: it is the real application on invented data. A permanent banner says so on
every screen, and it refuses to run over an existing installation.

📘 **[Full documentation](docs/en/README.md)** — installation, o2switch and cPanel specifics, monitor types,
alert channels, the detection engine explained, the CLI, translations, and troubleshooting.

---

## Built to be trusted

A monitoring tool that lies to you is worse than no monitoring tool. So the detection logic is tested against
real failures, and the interface is tested in a real browser.

```
php bin/selftest.php      278 checks   detection logic, offline, no network needed
php bin/bench.php          44 checks   real failures reproduced end to end (incl. badssl.com)
php bin/e2e.php           116 checks   full user journey over real HTTP, isolated instance
node bin/e2e-browser.mjs   57 checks   real Chromium: rendering, keyboard, mobile, contrast
php bin/chaos.php          33 checks   825 hostile requests from a user doing everything wrong
php bin/security.php       86 checks   OWASP Top 10, three depths, against a hostile local site
php bin/deadcode.php        —          unused methods, functions, classes, CSS, msgids, files
php bin/i18n-audit.php      —          translation coverage, per language
```

**614 checks, all green — plus zero dead code and a complete default catalogue.**

Two suites deserve a word.

**`bin/chaos.php`** plays a user who types badly, clicks everywhere, ignores every instruction, submits empty
and monstrous forms, and actively tries to break things: SQL injection, XSS, path traversal, 5 KB inputs, arrays
where strings are expected, exotic HTTP verbs. The contract it verifies is not "it works" but **"it never
breaks"** — no 500, no PHP notice leaking onto the page, nothing the user typed reflected into the HTML, and a
consistent database afterwards.

**`bin/security.php`** audits at three depths, every check labelled with its OWASP reference:

| Depth | What it does |
|---|---|
| **1 — light** | Configuration, secrets, cookie flags, exposed surface, dependency surface, static injection review |
| **2 — deep** | OWASP Top 10 as *active* tests on a live isolated instance: unauthenticated access to every screen and API action, forced browsing to source files, path traversal, CSRF on every write, 11 SQL-injection payloads across 5 parameters, reflected / stored / attribute XSS, response-header injection, session fixation, brute-force lockout, logout invalidation |
| **3 — very deep** | What targets the collector itself: SSRF (a monitored site that redirects to `file://`), XXE through a hostile sitemap, a 40 MB response, pathological content against the regexes, constant-time token comparison, indistinguishable heartbeat responses, spreadsheet formula injection, dynamic SQL identifiers |

**Five real defects were found by these suites and fixed**, three of them security issues:

| Found by | Defect |
|---|---|
| security, level 2 | **Session fixation** — the session id was not renewed on login (OWASP A07) |
| security, level 1 | **Spreadsheet formula injection** — a monitor named `=cmd\|…` executed when a client opened the CSV export |
| security, level 3 | **Unrestricted curl protocols** — a monitored site redirecting to `file://` would have been followed |
| chaos | Two crashes on malformed input |
| i18n unit tests | A cached number format that stopped following the language |

That is the point of having them.

---

## Under the hood

```
uptimer/
├── index.php · api.php · cron.php · beat.php · install.php    entry points
├── src/
│   ├── Runner.php            the collector: curl_multi, retries, incidents, alerts
│   ├── Check/Css.php         the nine layout signals + console reconstruction
│   ├── Check/Database.php    ~45 database-failure signatures
│   ├── Check/Ssl.php         two-pass certificate inspection
│   ├── Detect/Cms.php        technology fingerprinting
│   ├── Detect/Discovery.php  page selection + proof-string derivation
│   ├── Triage.php            turns states into a to-do list
│   ├── Diagnose.php          23 causes → what it means, what to do
│   ├── Tune.php              self-tuning thresholds + decisions journal
│   ├── Heartbeat.php         the dead-man switch
│   └── I18n.php              10 languages, RTL, plural rules
├── lang/                     one catalogue per language
├── views/                    templates — no framework, no build
├── assets/                   one CSS file, one JS file, no dependency
└── bin/                      the five test suites, demo data, i18n audit
```

**Design constraints, on purpose:**

- **No dependency, ever.** A monitoring tool that stops working because a package was unpublished is not a
  monitoring tool. Everything is PHP standard library.
- **Shared hosting is a first-class target.** Parallel checks via `curl_multi`, daily rollups so history stays
  cheap, work spread across cron passes. It runs on o2switch, cPanel, Plesk, or a €3 VPS.
- **SQLite by default**, MySQL when you outgrow it. Schema upgrades are automatic and non-destructive.
- **No emoji as icons.** One hand-drawn SVG set, consistent stroke.
- **The interface is the product.** Progressive disclosure everywhere: accordions that remember their state, a
  sticky save bar, contextual help behind a `?`, a command palette, keyboard shortcuts, and a Simple mode that
  hides everything you are not going to touch.
- **Security is a suite, not a claim.** Password hashing, CSRF on every write, session renewal on login,
  rate-limited login, `noindex` everywhere, an installer that locks itself, a heartbeat endpoint that cannot be
  enumerated, and an optional guard against private-range targets for the SSRF surface a URL-fetching tool
  inevitably has. All of it verified by `bin/security.php`, not asserted in a README.

---

## Roadmap

The [backlog](BACKLOG.md) holds the competitor research and the user stories behind each decision. Next up:

- Sitemap and CSV import sources
- A daily digest instead of per-event alerts, for people who prefer one e-mail
- Core Web Vitals on the pages that matter
- WordPress vulnerability watch on detected versions
- Automatic monthly client report by e-mail

---

## Contributing

Issues and pull requests welcome — particularly:

- **Translations.** Nine catalogues cover the operating interface; longer help texts fall back to English.
  `php bin/i18n-audit.php --manquants=xx` lists exactly what a language is missing.
- **Detection signatures.** A CMS or framework whose failure mode we do not recognise yet makes a good issue.
- **Corrections to the comparison table.** If a competitor gained a feature, say so and it gets fixed.

House rules: no dependency, no build step, French for the source strings (they are the msgids), a test for
anything that could regress, and comments that explain *why* rather than restate the code.

## Licence

MIT. Use it, sell services around it, fork it.

<div align="center">
<br>
<b>Uptimer</b> — because "the site is up" was never the question.
<br><br>
<sub>uptime monitoring · website monitoring · self-hosted monitoring · PHP monitoring tool · shared hosting
monitoring · broken CSS detection · database down detection · SSL certificate monitoring · cron job monitoring
· dead man's switch · status page · UptimeRobot alternative · Uptime Kuma alternative · agency website
monitoring</sub>
</div>
