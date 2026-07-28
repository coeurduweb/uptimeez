# Alerts

[← Detection](detection.md) · [Documentation](README.md) · [Reports →](reports.md)

An alert nobody reads is worse than no alert: it teaches you to ignore the channel. Everything here exists to keep
your alerts worth reading.

---

## Channels

| Channel | Setup | Notes |
|---|---|---|
| **Discord** | Channel → Settings → Integrations → Webhooks, paste the URL | Fastest to set up. Rich formatting, clickable link to the monitor |
| **Slack** | Incoming webhook URL | Same |
| **E-mail** | Recipients, sender | Server `mail()` (fine on o2switch) or direct SMTP with TLS/SSL |
| **Generic webhook** | Any URL | POSTs JSON. For n8n, Make, Teams, an SMS gateway, your own handler |

Set the **base URL** of your installation in Settings, otherwise alerts cannot include a clickable link back to
the monitor.

Then press **Test** on each channel. A real message goes through the real channel. An untested channel is not a
channel.

### Webhook payload

```json
{
  "event": "down",
  "monitor": { "id": 12, "name": "Camping des Pins", "url": "https://camping-des-pins.fr/" },
  "state": "down",
  "reason": "CSS_BROKEN",
  "title": "The page layout is broken",
  "message": "Broken layout: stylesheet failed: …/cache/min/1/absent.css → HTTP 404",
  "since": "2026-07-28 18:24:11",
  "link": "https://yourdomain.com/uptimer/index.php?p=monitor&id=12"
}
```

`event` is one of `down`, `degraded`, `up` (recovery), `group` (grouped outage) or `content` (content event).

---

## What triggers an alert, and what does not

**A monitor goes down.** Not on the first failure: it must fail *retries + 1* times in a row. Two retries by
default, which is what stops a two-second network hiccup from waking anyone.

**A monitor becomes "needs watching".** Slowness, certificate expiring, suspicious CSS, forgotten `noindex`. These
respect quiet hours, because none of them is an emergency.

**A monitor recovers.** On by default: knowing it is over without having to check is half the value.

**A content event.** A watched word appeared or disappeared, a page changed, CSS was redeployed.

**A grouped outage.** See below.

Nothing else. There is no "reminder that everything is fine", no daily volume of noise.

---

## Grouped outages — the noise killer

When three or more monitors fail on the **same IP address** within one pass, Uptimer sends **one** alert naming
the server and listing the affected sites, instead of one alert per site.

This is the difference between "my hosting provider had an incident, I got one message" and "my hosting provider
had an incident, I got forty messages and missed the one that mattered".

The IP comes from the check itself (`CURLINFO_PRIMARY_IP`), so it works without you declaring any topology,
dependency or parent-child relationship.

---

## Quiet hours

Format: `23:00-07:00`. It may cross midnight.

During the window, **"needs watching" alerts are held and grouped**. A real outage always gets through — nobody
should sleep through a site being down, and any tool that lets you configure that is helping you fail.

---

## Maintenance windows

Per monitor, in Full mode. Formats: `mon-fri 22:00-23:30`, `tue 02:00-04:00`, `sat 01:00-05:00`.

Inside the window, measurements continue — the history stays complete and honest — but alerts are silent. Use it
for a nightly backup that saturates the server, or a weekly deployment.

---

## Anti-repetition

| Setting | Default | Effect |
|---|---|---|
| Remind until resolved | 60 min | An open incident re-alerts at this interval. Set 0 for one alert only |
| Notify on recovery | on | One message when it comes back |
| Notify on "needs watching" | on | Turn off to be alerted on real outages only |

And **acknowledgement**: the *Acknowledged* button on a task card stops the reminders without closing the
incident. It says "I have seen it, I am on it" — the history stays accurate, your phone stops buzzing.

---

## Keeping alerts useful, in practice

A configuration that works well for an agency portfolio:

- **2 retries**, so a hiccup never alerts.
- **Quiet hours `23:00-07:00`**, so overnight slowness waits for the morning.
- **Reminder every 60 minutes** on open incidents.
- **Recovery notices on**, so nobody chases a resolved problem.
- **Discord for the team, e-mail for the on-call person** — set per monitor in Full mode with
  `notify_channels`.
- **Content changes off** except on sites that never publish, where a change means someone got in.

If you are still getting alerts you do not act on, the fix is upstream: either the threshold is wrong (let the
automatic tuning handle it) or the monitor should not exist.
