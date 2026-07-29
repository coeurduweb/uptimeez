# Security watch

**Uptimeez already knows which version runs on every site it watches. All that was left was to ask whether that
version has a published vulnerability.**

[← Documentation](README.md) · [Version française](../fr/veille-securite.md)

---

## Why it matters

A classic monitoring tool tells you when a site goes down. This one tells you **before**, when a security
advisory has just been published for the version your client is still running.

It rests on the same groundwork as broken-layout detection: Uptimeez already fetches the HTML of every page it
checks, so the information is right there, free of charge. It only had to be read.

![Software and known vulnerabilities](../img/vulnerabilities.png)

---

## Two signals, never mixed up

This is the point that decides whether you can trust this screen.

| Signal | What it means | What it is worth |
|---|---|---|
| **Published vulnerability** | An identified security advisory covers **exactly** the detected version. The identifier, date and link are shown. | Act on it. Nothing was guessed. |
| **Behind latest** | The installed version is older than the latest release. | Technical debt, not a vulnerability. Plan it. |

Mixing the two would be the shortest path to losing your trust. A tool that says "vulnerable" when it only knows
"not up to date" gets ignored within three weeks, and the day it is right, nobody is looking. So Uptimeez uses two
different words, two different colours, and never displays a severity it did not read in an advisory: when the
advisory announces none, the screen says "severity not announced".

---

## What is read, and where

Nothing extra is asked of the monitored site. Versions come from three places already present in the HTTP
response:

| Source | Example | Reliability |
|---|---|---|
| The `generator` tag | `<meta name="generator" content="WordPress 6.4.2">` | Good, but often truncated to the major version |
| The cache-busting parameter on static files | `/wp-includes/js/dist/url.min.js?ver=6.4.2` | Excellent, this is the core's real version |
| Component paths | `/wp-content/plugins/contact-form-7/…?ver=5.8.1` | Good for the plugin and its version |

When two readings disagree, **the more precise one wins**: "Drupal 10" from the `generator` tag gives way to
"10.1.6" read from `drupal.js`, because that is the number that decides whether a given advisory applies to this
site.

And when no version is readable, the component is recorded **without a version** rather than with an
approximation. It then shows as "not readable" and no lookup is made for it: there would be nothing to compare,
and a false security alert costs more than a missing one.

Inventoried: the core (WordPress, Drupal, Joomla, PrestaShop, TYPO3, Magento, Laravel, Symfony), WordPress
plugins and themes, PrestaShop and Drupal modules. Forty components per site at most, so a chatty page cannot
produce an endless inventory.

---

## The advisory sources

Two public sources, no account, no API key:

- **[OSV.dev](https://osv.dev)** for everything published on Packagist: Drupal, Laravel, Symfony, TYPO3,
  Magento, PrestaShop, Joomla. The response is already filtered by version, so a displayed advisory does cover
  the detected version.
- **api.wordpress.org** for the latest version of the WordPress core, its plugins and its themes.

### What leaves your server

Worth stating plainly, because it is the only outbound traffic from Uptimeez that does not go to a site you
monitor: the request sends **the component name and its version number**. Never the address of the site
concerned, never your client's name, never the full inventory in one call. An advisory source learns that
somebody is interested in `drupal/core 10.1.6`, and nothing more.

If that is still too much, the lookup can be switched off in **Settings → Security watch**. The version
inventory keeps working: it is local and costs no request at all.

### What it costs

One lookup **per component and per version**, cached for seven days. A portfolio of a hundred sites sharing a
dozen plugins therefore does not produce a hundred requests a day, but a few dozen the first time and almost
nothing afterwards. The pass is further capped at twenty-five lookups per daily maintenance run, and the
per-source timeout is short: the watch never delays a site check.

When a site is updated, the verdict resets. A site that moved from 6.4.2 to 6.7.1 does not stay flagged as
vulnerable while waiting for the next lookup.

---

## Where it shows up

**On a monitor's page**, a "Software and known vulnerabilities" panel lists the site's components with their
version, the latest published version, and the verdict. Red for a published vulnerability, amber for a version
behind latest, grey for nothing to report.

**On the home screen**, a published vulnerability becomes a task, exactly like an outage: what is broken, why it
matters, what to do. High-severity advisories are ranked above the rest.

**From an agent**, the `security_advisories` MCP tool returns the same inventory as JSON, and `monitor_detail`
carries the site's component list. See [MCP server](mcp.md).

---

## Settings

Under **Settings → Security watch**:

| Setting | Effect |
|---|---|
| Cross-check versions against published advisories | Switches off source lookups. The local inventory continues. |
| Lookup timeout | Time given to a source to answer, 8 seconds by default. |

From the command line:

```bash
php cron.php --vuln     # force a watch pass without waiting for 3 a.m.
```

In `config.php`, if you prefer files to screens:

```php
'vuln' => [
    'enabled'     => true,
    'timeout_sec' => 8,
],
```

---

## What the watch does not do

- **It does not scan your site.** No request is sent to test a vulnerability, no admin path is probed, no
  payload is injected. Uptimeez reads what the page publishes and queries public databases. A tool that actually
  tests vulnerabilities is a vulnerability scanner: that is a different job, and it runs with written
  authorisation.
- **It does not see what the HTML does not say.** A plugin that loads neither CSS nor JavaScript on the homepage
  is invisible. A site that strips version parameters from its static files gives up its inventory without the
  numbers. That is a deliberate limit: a partial and exact inventory beats a complete and guessed one.
- **It does not replace automatic updates.** It tells you what is behind and what is dangerous. Applying the fix
  stays a human decision, on a site whose quirks you know.

---

## Troubleshooting

**No component listed for a site.** Has the monitor been checked at all since this feature was added? The
inventory is written on the next check. Force it with *Check now*, or wait for the next pass.

**Components listed, but nothing checked.** Lookups happen during the 3 a.m. maintenance run.
`php cron.php --vuln` forces one immediately. If the column stays empty, your host is probably blocking outbound
calls to `api.osv.dev` and `api.wordpress.org`: the bench says so
(`php bin/bench.php`, *Security watch* section).

**A detected version that does not match reality.** A stale HTML cache or a CDN can serve an old page. The
version shown is the one the world sees, which is useful information in itself. If the gap persists after
clearing the cache, that is a good issue to open: include the URL.

---

[← Documentation](README.md) · [Detection](detection.md) · [Reports](reports.md) · [MCP server](mcp.md)
