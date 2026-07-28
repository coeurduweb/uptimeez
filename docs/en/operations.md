# Operations

[← Reports](reports.md) · [Documentation](README.md)

Running it day to day: the collector, the command line, backups, translations, and what to do when something looks
wrong.

---

## The collector

`cron.php` is the only thing that needs to run on a schedule. One pass per minute, whatever your intervals:
Uptimer picks the monitors that are due itself.

```
* * * * * /usr/local/bin/php /path/to/uptimer/cron.php >/dev/null 2>&1
```

What a pass does:

1. selects monitors whose `next_check_at` has passed;
2. fetches them in parallel with `curl_multi` (10 at a time by default);
3. runs the checks, opens and closes incidents, applies retries;
4. sends the alerts, grouping by IP where it applies;
5. finishes any pending automatic setup, at a controlled rate;
6. once a day: daily rollups, uptime recomputation, history purge, RDAP domain checks.

**Useful flags:**

```bash
php cron.php --once      # one pass, verbose output : what you run to debug
php cron.php --setup     # only finish pending automatic setups
php cron.php --maint     # only the daily maintenance
```

A lock file in `data/` prevents two passes from overlapping. If a pass is killed, the lock expires on its own.

**Load.** Ten sites at 5-minute intervals is 2 checks per minute. A hundred sites is 20. The parallel fetch means a
pass takes about as long as the slowest site, not the sum of all of them. If your host throttles outgoing
connections, lower *simultaneous requests* to 5 in the settings.

---

## Command line

```bash
php bin/selftest.php          # 305 checks: detection logic, offline, no network
php bin/bench.php             # 44 checks: real failures reproduced end to end
php bin/e2e.php               # 116 checks: full user journey, isolated instance
node bin/e2e-browser.mjs      # 57 checks: real Chromium
php bin/chaos.php             # 33 checks: 825 hostile requests, nothing must break
php bin/chaos.php --long      # adds the bulky payloads
php bin/security.php          # 86 checks: OWASP Top 10, three depths
php bin/security.php --niveau=1   # light only: configuration, secrets, surface
php bin/security.php --niveau=2   # deep only: active OWASP tests
php bin/security.php --niveau=3   # very deep only: SSRF, XXE, bombs, timing
php bin/deadcode.php          # unused methods, functions, classes, CSS, msgids
php bin/deadcode.php --strict # exit code 1 if anything is dead : for CI
php bin/mcp.php               # MCP server for agents, read-only
php bin/mcp.php --write       # also expose the four writing tools
php bin/i18n-audit.php        # translation coverage
php bin/demo.php              # install the demo portfolio
php bin/demo.php --purge      # remove it
node bin/shots.mjs            # regenerate the documentation screenshots
```

Everything runs offline except `bin/bench.php` (which deliberately reaches badssl.com to verify certificate
detection against real broken certificates).

### Demo mode

`php bin/demo.php` builds a 13-site portfolio on recognisable domains with 30 days of history and the four
flagship failures. It is the real application on invented data:

- the interface shows a permanent banner saying the measurements are fictional;
- the failures sit on staging subdomains that do not exist, so nothing implies anything about a real service;
- it refuses to run if a real installation is present, and `--purge` removes every trace.

Use it to evaluate the tool, to reproduce a bug report, or to regenerate the documentation screenshots.

`bin/selftest.php` is the one to run after any upgrade or hosting change: it needs no network and tells you in two
seconds whether detection works on this server.

---

## Backups

Two things to back up:

| What | Why |
|---|---|
| `config.php` | Password hash, webhook URLs, every setting |
| `data/uptimer.sqlite` | All your history |

On SQLite, copy the file when no pass is running, or use the safe route:

```bash
sqlite3 data/uptimer.sqlite ".backup '/backups/uptimer-$(date +%F).sqlite'"
```

Restoring is putting the two files back. There is no other state anywhere.

**Retention.** Detailed measurements are kept for 60 days by default. Daily statistics are kept **indefinitely**,
which is why the 6-month and 1-year views stay accurate on a database that stays small. A hundred monitors at
5-minute intervals is roughly 250 MB of raw measurements over 60 days, and a few megabytes of aggregates per year.

---

## Translations

Ten languages: English (default), Chinese, Hindi, Spanish, Arabic, French, Bengali, Portuguese, Russian, Urdu.
Arabic and Urdu are laid out right to left.

The language is chosen without ever asking: `?lang=xx` in the URL → the remembered choice → the instance setting →
the browser's `Accept-Language` → English.

**How it is built.** Translation keys are the French source sentences, the way gettext uses msgids. A missing key
falls back to English, then to the source text : never to a technical code like `nav.today.label`.

```bash
php bin/i18n-audit.php                  # overall coverage
php bin/i18n-audit.php --manquants=es   # what Spanish is still missing
php bin/i18n-audit.php --nus            # visible strings not yet translatable
```

English and French are complete. The eight other catalogues cover the operating interface : navigation, states,
actions, the 23 diagnoses : and longer help texts fall back to English. To complete one, run the audit for that
language and add the missing keys to `lang/xx.php`. Pull requests very welcome.

**Plurals.** Two source forms, and languages needing more put the extra forms in the plural translation separated
by `|`. Russian's three forms:

```php
'{n} sites à remettre en ligne' => '{n} сайта нужно вернуть в строй|{n} сайтов нужно вернуть в строй',
```

---

## Interface detail level

The **Simple / Full** switch is stored per browser (cookie) with an instance-wide default in
`config.php`:

```php
'app' => [ 'ui_mode' => 'simple' ],   // or 'expert'
```

Simple is the default on purpose. It hides settings, not capabilities: everything keeps working, and a form field
that is hidden is still submitted with its current value : so switching modes can never disable a monitor by
accident.

---

## Troubleshooting

### "The scheduled task has never run"

Shown on the home screen, and it is the most common setup problem.

1. Check the cron line uses the **CLI** PHP binary. On o2switch that is `/usr/local/bin/php`, not the web SAPI.
2. Run it by hand: `php /path/to/uptimer/cron.php --once`. Any error appears immediately.
3. No crontab? Use the URL trigger from Settings and call it from an external scheduler.

### Every site reports a timeout

Your host blocks outgoing connections, or throttles them.

1. Test from the server: `curl -I https://example.com`.
2. Lower *simultaneous requests* to 3-5.
3. Raise the timeout to 30 s.
4. On a restrictive host you may need to have outgoing HTTP allowed for your account.

### A false "broken layout"

That is a bug we want. Open the *Page resources* accordion: it names the resource and the reason. Common genuine
causes: an asset CDN that blocks the monitoring User-Agent (set a browser User-Agent in the monitor's advanced
settings), or an intentional redesign (press *Relearn the reference*).

If neither applies, open an issue with the URL. False positives are treated as defects, not as tuning.

### The proof string was not found

Uptimer needs a piece of text distinctive enough. Some sites have nothing usable : no footer copyright, no
`og:site_name`, a generic title. Set it by hand on the monitor page: pick something that comes from the database
and never appears on an error page.

### 500 error after an upgrade

The schema upgrades itself on the next request, so this is nearly always a PHP version or extension problem.
Run `php bin/selftest.php`: it reports the PHP version and the missing extension by name.

### The database seems locked (SQLite)

Two passes ran at once, or a pass was killed while writing. `PRAGMA integrity_check` is run by the chaos suite; you
can run it too:

```bash
sqlite3 data/uptimer.sqlite "PRAGMA integrity_check;"
```

If it does not answer `ok`, restore your backup. If it happens repeatedly, your host's filesystem does not handle
SQLite locking well : switch to MySQL.

---

## Auditing your own installation

```bash
php bin/security.php
```

Three depths, each check labelled with its OWASP Top 10 reference. Levels 2 and 3 spin up an isolated instance
and a deliberately hostile local site : nothing touches your installation, nothing leaves your machine.

| Level | Covers |
|---|---|
| 1 : light | A02 crypto, A05 misconfiguration, A03 static injection review, A04 design guards, A06 dependencies, A09 logging |
| 2 : deep | A01 access control, A03 injection (SQL, XSS, headers), A04 CSRF, A05 runtime configuration, A07 authentication |
| 3 : very deep | A10 SSRF, A03 XXE and spreadsheet formula injection, denial-of-service bounds, constant-time comparison |

Run it after changing hosting, after an upgrade, and before exposing the interface to anyone else.

**The optional SSRF guard.** A monitoring tool fetches the URLs you give it : monitoring an intranet or a
staging site on `192.168.x` is a legitimate use, so nothing is blocked by default. If your interface is reachable
by people you do not fully trust, turn the guard on:

```php
'security' => [ 'block_private_ranges' => true ],
```

It then refuses loopback, private ranges and the `169.254.169.254` metadata address : the classic SSRF target.

---

## Security notes

- Password stored with `password_hash()`, login rate-limited after six failed attempts per IP.
- The session id is renewed on login, so a session imposed before authentication cannot survive it.
- curl is restricted to HTTP and HTTPS, on the initial request *and* on redirects: a monitored site cannot make
  the collector read a local file by redirecting to `file://`.
- Spreadsheet exports neutralise leading `=`, `+`, `-` and `@`, so a monitor name can never become a formula in
  the client's Excel.
- CSRF token on every write, checked on every POST.
- Sessions are `HttpOnly`, `SameSite=Lax`, `Secure` behind HTTPS.
- Every page sends `noindex, nofollow`.
- `install.php` refuses to run once installed, and answers 403 to a POST.
- `beat.php` returns an identical 404 for an unknown and for a malformed token : no key enumeration.
- The public status page requires its token and exposes nothing else.
- Everything the user types is escaped on output; the chaos suite verifies no input is ever reflected raw.
