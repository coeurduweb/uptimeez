# Reports and status pages

[← Alerts](alerts.md) · [Documentation](README.md) · [Operations →](operations.md)

Two ways to show someone else what you have been doing: a report you send, and a page they can open themselves.

---

## The client report

**Report** in the navigation (Full mode, or straight from the palette). Pick a site and a period, then print or
save as PDF.

![Printable client report with uptime figures, day strip, chart and incidents](../img/report.png)

It contains:

- **Availability** over the period, and the cumulative downtime in plain hours and minutes;
- **The day strip** — one square per day: green for a full day up, orange for a brief incident, red for an outage
  longer than fifteen minutes. A client understands this in one second;
- **Response time** — average and p95, with the curve;
- **Per-page detail**, so "the site" is not a black box;
- **The incident table** — start, end, duration, cause;
- A footer stating the document was produced automatically, with no human intervention.

The print stylesheet strips the navigation, the buttons and the toasts: what comes out of the printer is a
document, not a screenshot of an application.

**Ranges:** 24 h, 7, 30, 90, 120, 180 and 365 days. Beyond 40 days the curve is rebuilt from daily aggregates,
which are kept indefinitely — so a one-year report stays accurate even though the raw measurements have been
purged.

---

## The public status page

**Settings → Public status page token.** Enter a random string; the page becomes available at:

```
https://yourdomain.com/uptimer/index.php?p=status&token=YOUR_TOKEN
```

No session, no password, no access to anything else. You hand that link to a client so they can see their sites
without an account in your monitoring tool.

Leave the token empty to disable the page entirely. Change the token to revoke a link that has spread too far.

The page shows the current state of every monitored service and when it was last updated. It respects the
visitor's language: a client in Madrid gets Spanish, one in Cairo gets Arabic laid out right to left.

---

## The report you paste into a ticket

On every task card: **Copy the report**. It puts a plain-text summary on the clipboard:

```
# Camping des Pins — Down

Monitored URL: https://camping-des-pins.fr/
Observed on 28/07/2026 19:12 (timezone Europe/Paris)
Technology: WordPress

## Diagnosis
The page layout is broken
The page answers, but the resources that style it cannot be used: visitors see a bare,
empty or broken page.

Technical reading: Broken layout: stylesheet failed: …/cache/min/1/absent.css → HTTP 404 [WP cache]

Errors the browser reports:
  net::ERR_ABORTED 404 (Not Found)  …/wp-content/cache/min/1/absent.css

## What to do
Open “Page resources” below: every faulty file is listed there with its exact cause.
After an intentional redesign, relearn the reference.

## Timeline
Start: 28/07/2026 18:24
Ongoing for 48 min
Failed checks: 8

## Availability
24 hours: 97.58 % (35 min down)
Average response time: 334 ms · p95 512 ms

— Report produced by Uptimer
```

No HTML, no markup to clean up. It goes into a ticket, an e-mail or a Slack message as it is — with the evidence
already in it, which is what gets a developer to act instead of asking questions.

---

## Incident export

**Incidents → CSV export** gives you the incidents for the period as a spreadsheet: monitor, start, end, duration,
cause, number of failed checks, alerts sent.

That is your SLA evidence. If a contract says 99.5 %, this is the file that proves you met it — or the file that
tells you which host to leave.
