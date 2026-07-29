# Monitors

[← Getting started](getting-started.md) · [Documentation](README.md) · [Detection →](detection.md)

A monitor is one thing being checked. A site groups several of them, and the site's state is the state of its
worst monitor : except that a paused monitor never drags a healthy site down.

---

## The five types

| Type | What it does | Use it for |
|---|---|---|
| **Web page** | Full check: HTTP, TLS, proof string, page resources, `noindex`, content fingerprint | Almost everything |
| **JSON API** | Request with method, headers and body; asserts a field path and value | Health endpoints, internal APIs |
| **Asset** | Fetches a specific file and checks it is served correctly | A PDF, a feed, a critical script |
| **Keyword** | A page, but only interested in the presence or absence of a text | Cheap check on a heavy page |
| **Heartbeat** | Waits to be called. **Silence raises the alert** | Cron jobs, backups, nightly imports |

### The heartbeat, in detail

This one is different in kind: it is the only way to monitor something that produces no HTTP surface. Create a
heartbeat monitor, copy the line it gives you, and put it at the end of the script you care about:

```bash
curl -fsS --max-time 10 "https://yourdomain.com/uptimeez/beat.php?k=TOKEN" > /dev/null
```

Add `&m=some+text` to attach a word to the signal : a file count, a duration, a row total. If the signal does not
arrive within the interval plus the grace period, an incident opens. The next signal closes it and sends the
recovery notice.

An unknown or malformed token returns exactly the same `404` as a token that does not exist, so the endpoint
cannot be used to enumerate valid keys.

---

## Every setting, and whether you should touch it

Fields marked **auto** are decided for you and re-decided as measurements accumulate. A value you type always
wins, permanently.

### Identity and rate

| Field | Default | Touch it when |
|---|---|---|
| Name | the site name | It should read well in an alert |
| Monitored address | as pasted | A bare domain becomes `https://`, redirects are followed. If HTTPS does not answer at all, Uptimeez retries over HTTP, says so, and monitors anyway |
| Check frequency | **auto** by importance | You want a pricing page every minute. Shorter means faster detection and more load on the site |
| Group | empty | You want to filter the wall by client |
| Monitor enabled | on | Unchecking keeps the monitor and its history but stops checking it |

### The proof string : the most valuable field on this page

| Field | Default | Notes |
|---|---|---|
| Proof string | **auto**, derived from the content | The text that proves the web server *and* the database answer. Several variants accepted, separated by `|` |
| Forbidden string | empty | Its presence raises an immediate alert: "Site under maintenance", "Connection error" |

Why it matters: without it, an empty page returning `200` passes as valid. With it, a database outage is caught
in one check even though the HTTP status is perfect.

Uptimeez derives it in this order of preference: footer copyright (which comes from the site settings, hence from
the database) → `og:site_name` → page title → first navigation entry → H1. Boilerplate is rejected ("all rights
reserved", "home"), and it is never taken from a page that looks like an error page. If nothing distinctive is
found, the monitor appears in *Coming up* asking you to set it by hand.

### Checks to run

| Switch | Default | Notes |
|---|---|---|
| Check page resources | on | CSS, scripts and fonts : this is what detects a broken layout. [How it works](detection.md) |
| Detect a database outage | on | ~45 error signatures, plus the proof string |
| Monitor the TLS certificate | on | Validity, chain, domain match, expiry |
| Warn before certificate expiry | 14 days | Let's Encrypt renews itself; this catches the times it does not |
| Alert on a forgotten `noindex` | on for production | The silent SEO killer after a release |
| Monitor a content update | off | Tell me when a text appears (publication confirmed) or disappears |
| Report any content change | off | Fingerprint of the visible text. Noisy on a site that publishes often : keep it for static sites |
| Freeze the current CSS reference | off | Turn on once the design is settled: the reference stops evolving on its own |

### Thresholds

| Field | Default | Notes |
|---|---|---|
| Slowness threshold | **auto** from p95 | Beyond it, the monitor turns "needs watching" without being declared down |
| Tune automatically | on | Recomputed from this monitor's own p95 × 1.8, with a 6-hour cooldown and a ±20 % deadband so it never oscillates |
| Timeout | 15 s | Raise it for a genuinely slow site rather than lowering the slowness threshold |
| Retries before alerting | 2 | What stops a two-second network hiccup from raising an alert |
| Tolerated CSS drop | 35 % | A cleared cache moves it a few per cent; a failed deployment halves it |

### Access, maintenance and alerts (Full mode)

| Field | Notes |
|---|---|
| HTTP username / password | For a staging site behind HTTP authentication |
| User-Agent | Customise it if a firewall blocks the monitoring robot |
| Ignore certificate errors | Staging sites with self-signed certificates only |
| Accepted HTTP codes | Comma-separated. For a page that legitimately answers 401 or 403 |
| Maintenance window | `mon-fri 22:00-23:30`, `tue 02:00-04:00`. Measurements continue, only alerts go quiet |
| Alert channels | Empty means every channel enabled globally. Otherwise `discord,mail` |

---

## Working on many monitors at once

The **Monitors** list is built for portfolios: filter by name, domain or technology; sort by state, slowness or
last check; then select rows and apply a bulk action : pause, resume, change the interval, run auto-detection
again, delete.

The filter is accent- and case-insensitive in every language: `casse` finds `cassé`, `munchen` finds `München`.

---

## Deleting versus pausing

**Pause** keeps everything and stops checking. Use it for a site being rebuilt.

**Delete** removes the monitor, its measurements, its incidents and its events, permanently. The confirmation
dialogue names the monitor so you cannot delete the wrong one by muscle memory.

There is no undo for a deletion : that is why the button lives inside a folded accordion labelled *cannot be
undone*, and why pausing is suggested right next to it.
