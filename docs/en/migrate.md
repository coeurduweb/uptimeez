# Bring over a portfolio monitored elsewhere

**Drop your current tool's export. Uptimer recognises it, shows you what it will create, and tells you what it
cannot carry over.**

[← Documentation](README.md) · [Version française](../fr/reprise.md)

---

## The real obstacle to switching

It is not the price, it is the evening spent retyping forty monitors. With their check rates, their keywords, their
paused sites. Nobody wants to do that, so nobody switches, even when the tool no longer fits.

Five exports are read directly, with nothing to pick from a menu:

| Tool | What to provide |
|---|---|
| **UptimeRobot** | `getMonitors` API response, or the dashboard CSV export |
| **Uptime Kuma** | Settings → Backup → Export (JSON) |
| **Better Stack** | `/api/v2/monitors` API response |
| **Pingdom** | `/api/3.1/checks` API response |
| **Site24x7** | `/api/monitors` API response |

Plus a **generic CSV** for everything else: all it takes is a header containing `url`, `website`, `hostname` or
`adresse`. The `name`, `interval`, `keyword` and `active` columns are picked up when present, in English or French,
in any order.

![Migration preview](../img/import-reprise.png)

---

## How it goes

1. **Add sites screen** → *Or drop your current tool's export*.
2. Uptimer recognises the format **by its content**, never by the file name: a renamed export works, and a file
   called `uptimerobot.json` that is not one fools nobody.
3. The preview appears: what will be created, at what rate, with which proof string, and what cannot be carried
   over.
4. You confirm. Technology detection, page selection and the proof string then run site by site, exactly as for a
   manual addition.

Nothing is created before you confirm.

---

## What carries over

| Setting | Behaviour |
|---|---|
| Address | Taken as is. Pingdom stores hostname and path separately: the address is rebuilt, encryption included. |
| Name | Taken over. Failing that, Uptimer derives one from the domain. |
| Check rate | **Taken as is**, and labelled as such in the preview. A one-minute rate next door stays one minute here: somebody chose it. |
| Expected keyword | Becomes the proof string. |
| Keyword that triggers the alert | Becomes a **forbidden string**, which is not the same thing. See below. |
| Paused monitor | **Created paused.** Re-enabling it would be deciding for you. |
| Accepted HTTP codes | Taken over when the export provides them. |
| Method, retries, timeout | Taken over when the export provides them (Uptime Kuma does). |

### What the keyword means, which is easy to get backwards

The tools do not share a convention, and getting it wrong inverts the alert:

- **UptimeRobot**: `keyword_type` set to "exists" means "alert if the text is there". So it is a **forbidden**
  string here, not a proof string.
- **Uptime Kuma**: `invertKeyword` performs the same flip.
- **Better Stack**: the `keyword_absence` type alerts when the text is present.
- **Pingdom**: `shouldcontain` is a proof string, `shouldnotcontain` a forbidden one.
- **Site24x7**: `matching_keyword` is expected, `unmatching_keyword` is forbidden.

Uptimer honours each convention. The tests check it, for each of the five tools.

---

## What does not carry over, and why

**Monitors with no equivalent.** A TCP port, an ICMP ping, a DNS lookup, an SMTP test: Uptimer monitors over HTTP.
Those monitors appear in a list, with the reason, and are not created. An import that silently loses six monitors
out of forty is worse than one that refuses.

**Heartbeats.** A dead-man switch depends on a secret URL the called script must know. Carrying it over would make
no sense: create the monitor here to get a new URL, then paste it into the script. See [Monitors](monitors.md).

**The measurement history.** This is the most important point, and the most tempting one to ignore. Those
measurements were taken by another tool, with other thresholds, from another network, at another frequency.
Displaying them as its own would be a lie: a "99.98 %" carried over from Pingdom says nothing about what Uptimer
would have measured. So the uptime counter starts from zero, and that is stated before the import.

**Alert contacts.** Channels are configured once for the whole installation, not per monitor. See
[Alerts](alerts.md).

---

## Migrating without a gap

The right way to switch is not to cut the old tool off:

1. Bring the portfolio into Uptimer and let the scheduled task run.
2. Keep the old tool running for a few days, alerts included.
3. Compare: on a real incident, both should alert. If Uptimer sees an outage the other missed, that is the most
   common case, and usually a broken layout or a database down behind a 200.
4. Switch the old one off when you no longer have doubts.

---

## Limits

| Limit | Value |
|---|---|
| Accepted file size | 4 MB |
| Monitors carried over at once | 500 |
| File formats | Text: JSON, CSV, TSV. A binary file is rejected before parsing. |

The dropped content is read as text and never executed, the file name is used for nothing, and the import screen is
unreachable without being authenticated. All three are verified by the security suite.

---

## Troubleshooting

**"Format non reconnu".** The file is neither one of the five exports nor a CSV with an address column. Simplest
fix: open it in a spreadsheet, keep a `url` column and a `name` column, export as CSV.

**Every check rate is five minutes.** The export carried no interval, so the value chosen on the import screen
applies. The preview then did not show the "taken from the export" note.

**An imported monitor is not being checked.** It was paused in the export, so it was created paused. The preview
said so with the "to create, paused" note. Re-enable it from its page.

**Duplicate monitors.** An address already monitored is never recreated: the preview marks it "already there".
Re-submitting the same export creates nothing.

---

[← Documentation](README.md) · [Getting started](getting-started.md) · [Monitors](monitors.md) · [Alerts](alerts.md)
