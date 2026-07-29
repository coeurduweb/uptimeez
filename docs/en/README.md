# Uptimer documentation

**Uptime monitoring that tells you what to do.** Everything you need to install, run and trust Uptimer.

[← Back to the project](../../README.md) · [Version française](../fr/README.md)

---

## Start here

| I want to… | Read |
|---|---|
| Get it running on my hosting | **[Installation](installation.md)** : requirements, shared hosting, cPanel/o2switch, MySQL |
| Add my first sites and understand the screens | **[Getting started](getting-started.md)** : the 5-minute tour |
| Know what each option does | **[Monitors](monitors.md)** : types, rates, proof strings, every setting |
| Understand *how* it detects things | **[Detection](detection.md)** : the nine layout signals, database failures, certificates |
| Know why a page is slow | **[Perceived speed](speed.md)** : what is measured, what is inferred, and why the two never mix |
| Know whether a site runs a vulnerable version | **[Security watch](security-watch.md)** : version inventory, published advisories, what leaves your server |
| Receive alerts where I actually look | **[Alerts](alerts.md)**. Discord, Slack, e-mail, webhooks, quiet hours |
| Show something to a client | **[Reports and status pages](reports.md)** |
| Give each client access to their own sites only | **[Agency mode](agency-mode.md)** : one read-only link per client, revocable |
| Ask it questions from Claude or another agent | **[MCP server](mcp.md)**: setup, the fifteen tools, why it is read-only by default |
| Run it day to day | **[Operations](operations.md)** : cron, CLI, backups, upgrades, translations, troubleshooting |

**In a hurry?** Three commands and you are monitoring:

```bash
php bin/demo.php            # a demo portfolio to look around (password: demo1234)
php -S 127.0.0.1:8390 -t .  # open http://127.0.0.1:8390/
php bin/demo.php --purge    # then start clean with install.php
```

---

## The idea in one page

Uptimer assumes three things about you.

**1. You look after sites that belong to other people.** So a failure is not an abstraction, it is a phone call.
The home screen is therefore a to-do list, not a dashboard: each entry says what broke, why it matters, what to
do about it, and carries the buttons that do it.

**2. You do not have time to configure anything.** So Uptimer decides for you and tells you what it decided.
Paste a list of domains: it fingerprints the technology, picks representative pages from the sitemap, derives a
proof string from the site's own content, sets the check rate from the page's importance, and tunes the slowness
threshold from measured p95. Every decision is written down in a journal you can read, and every one of them can
be overridden by hand : a manual value always wins.

**3. You will be woken up only when it is real.** So a failure must survive retries before it becomes an
incident, ten sites on one IP produce one alert instead of ten, "needs watching" alerts respect your quiet
hours, and a real outage always gets through.

Everything else follows from those three.

---

## Vocabulary

A few words are used consistently across the interface and this documentation.

| Word | Meaning |
|---|---|
| **Site** | A domain you look after. Groups one or more monitors. |
| **Monitor** | One thing being checked: a page, an API, an asset, a keyword, or a heartbeat. |
| **Main monitor** | The site's reference monitor : usually the homepage. Its state is the site's state. |
| **Proof string** | A piece of text that can only come from the database. Its presence proves the web server *and* the database are answering. |
| **Reference (baseline)** | The learned fingerprint of a healthy page's resources: stylesheet weight, rule count, class coverage, media queries. |
| **Client** | Someone whose sites you look after. Gets a link to a read-only space. |
| **Component** | A piece of software spotted on a site: the core, a plugin, a theme, with its version when readable. |
| **Pass** | One run of the collector (`cron.php`). Each pass checks only the monitors that are due. |
| **Incident** | An uninterrupted period during which a monitor was down. Opens on failure, closes on recovery. |
| **Heartbeat** | A monitor that waits to be called instead of calling. Silence raises the alert. |
| **Simple / Full** | The interface detail level. Simple shows only what you can act on. |

---

## Where things live

```
uptimer/
├── config.php        your configuration : never commit this file
├── data/             the SQLite database, the cron lock, the demo site
├── lang/             translation catalogues, one per language
├── src/              the engine
├── views/            the screens
├── assets/           one CSS file, one JS file
└── bin/              tests, demo data, i18n audit
```

Two files matter to you: `config.php` (written by the installer and the settings screen) and `data/` (your
history). Back up both. Everything else is code you can replace wholesale on upgrade.

---

## Support and contributions

- Something detected wrongly? Open an issue with the URL : false positives are treated as bugs.
- A CMS whose failure mode is not recognised? That is a good issue too, and usually a five-line fix.
- Want a language completed? `php bin/i18n-audit.php --manquants=xx` lists exactly what is missing.

House rules, if you send code: no dependency, no build step, a test for anything that could regress, and
comments that explain *why*.
