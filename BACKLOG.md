# UptimeEZ: competitor research and product backlog

A working document, and a design brief: every interface decision below points back to something
observed on a competing product rather than to a preference.

**Competitor research: July 2026. Delivery status: 2 August 2026.**
The research section ages slowly — these products change over months, not weeks. The status marks
age fast, and have been wrong before: on 29 July, six items marked "ready to build" turned out to
have shipped several iterations earlier. A backlog running behind the code sends people to rebuild
what already exists, which costs more than an empty backlog.

---

## 1. What users say about the competition

| Product | Acknowledged strengths | Recurring complaints |
|---|---|---|
| **UptimeRobot** | instant setup, generous free tier, name recognition | "Consistently bad UI", "Confusing UI"; false positives at scale; billing per alert contact; poor alert customisation; no scripted checks |
| **Uptime Kuma** | responsive, pleasant interface, 20 s intervals, push monitors, status page, self-hosted | everything is configured monitor by monitor, by hand; no automatic detection; unmanageable past ~100 monitors; no notion of a "site" |
| **Site24x7** | very broad feature coverage | "cluttered with configuration, click-heavy, cramped"; overwhelming for a newcomer; alert management frustrating as the estate grows |
| **Checkly** | monitoring-as-code, native Playwright, excellent for a dev team | "the interface can be downright confusing"; deliberately narrow scope; assumes you can code |
| **Zabbix** | power, free, extensible | "stuck in the past"; steep learning curve; hosts/items/triggers/templates built one at a time; fine-grained alerting is expert-only |
| **New Relic** | analytical depth | alert fatigue acknowledged by the vendor itself, which sells "decisions" to correlate the noise |
| **SiteGuru** *(SEO, not uptime)* | **turns audit data into a prioritised to-do list**; "SEO audits that actually tell you what to fix"; clean interface | SEO scope only |

### Three findings that shape everything else

1. **Configuration cost is the real barrier.** Kuma, Zabbix and Site24x7 all require declaring
   everything by hand. Nobody offers to *infer* the settings from the site itself.
2. **Alert noise is the second barrier.** UptimeRobot is criticised for false positives; New Relic
   sells a correlation layer. One alert per site when a server goes down is an anti-pattern.
3. **Data is worth nothing without the course of action.** The only product on this list praised
   unanimously for its UX is SiteGuru, precisely because it answers "here is what to fix, in this
   order". No uptime tool does that.

### The resulting position

> The others show you **states**. UptimeEZ gives you **a list of things to do**, and guesses
> everything else.

Three design rules, each of which can be held against any screen:

- **Zero configuration to start.** Whatever can be inferred from the site is inferred, and the tool
  explains what it decided instead of asking.
- **One screen, read top to bottom, stop when it is green.** No dashboard to interpret: a queue of
  priorities.
- **Every problem carries its action.** The action happens in place, never in a settings screen.

---

## 2. Backlog

Key: **✅ shipped** · **◐ partial** · **▶︎ ready** (specified, to build) · **◻︎ to scope**.

### Epic A: nothing to configure

**A1 ✅ As an agency owner, I paste a list of domains and there is nothing else to do.**
- Given any text (domains, URLs, `client | domain` lines, addresses buried in prose)
- When I paste it and confirm
- Then UptimeEZ extracts the candidates, drops duplicates, detects the technology, picks
  representative pages, infers the proof string and creates the monitors, with no further input.

**A2 ✅ As a user, I see what is about to be created before I confirm.**

**A3 ✅ As a user, thresholds set themselves from what the site actually does** (measured p95), not
from a round number.

**A4 ✅ As a user, the tool tells me what it decided and why**, so I can disagree with it.

**A5 ✅ As a user, adding one site by hand is still possible** for an API, a protected page or an
unusual file.

**A6 ◻︎ As an agency, I connect my host's API (o2switch/cPanel) to import the domains.**

### Epic B: go straight to what needs doing

**B1 ✅ The home screen is a to-do list, not a dashboard.**
- Three blocks: *Handle now*, *Coming up*, *All fine* (collapsed to one line).
- Criterion: when all is well, the page fits in less than one screen and shows a green sentence.

**B2 ✅ Every problem tells me what to do and lets me do it in place.**
- Cause, impact, course of action, then the actions: re-check, open, copy the report, relearn the
  baseline, pause, open the record.
- Criterion: none of these actions leaves the page; each gives immediate visual feedback.

**B3 ✅ I am warned about what is *going* to break.**
- Certificate under 30 days, domain under 45 days, sustained slowdown, CSS drift, `noindex`.
- Criterion: a +50 % slowdown over 3 days appears in "Coming up" before any outage.

**B4 ✅ Everything is reachable from the keyboard** — command palette (`Ctrl/⌘ K`).
- Criterion: add a site from any screen in 2 keystrokes.

**B5 ✅ One action too many can be undone.** Anything destructive offers "Undo" for 8 seconds.

**B6 ✅ Problems are grouped by probable cause.** "These 6 sites share server 51.x.x.x" surfaces as
a single item to handle.

### Epic C: not drowning in alerts

**C1 ✅ A server outage costs me one alert, not forty.** Correlated by contacted IP, threshold at 3
distinct sites, message that names the server.

**C2 ✅ I can cut the noise without cutting the monitoring.** Quiet hours (real outages still get
through), maintenance windows, acknowledgement.

**C3 ✅ A recurring alert offers to tune itself.** After 3 slowness alerts in 7 days on the same
monitor, UptimeEZ offers to raise the threshold in one click.

**C4 ▶︎ A daily digest instead of per-event alerts for the non-urgent.** 08:00 digest: what was
detected, what is fixed, what is coming.

**C5 ✅ *(August 2026)* When the check is the one that is wrong, I can say so — and it changes no
verdict.** Four reasons, three scopes, on every incident. The scope is the load-bearing part:
*normal here* and *normal everywhere* call for opposite fixes, and without the distinction one
operator would degrade detection for everyone else. Silence is never counted as agreement.

**C6 ✅ *(August 2026)* I can silence a signal on one page, and that one really acts.** It stays
**counted** — "12 alerts silenced by your exceptions this month" — and carries a mandatory review
date, six months by default, because an exception set during a migration outlives the migration.
**No setting can silence an outage**: beyond the list of excusable appearance causes, the engine
refuses to hide any verdict that is not *degraded*.

### Epic D: watching what the others do not see

**D1 ✅ Broken layout**: 9 cross-checked signals, reconstructed console messages. *(No mainstream
competitor does this.)*
**D2 ✅ Database down behind a 200**: error signatures + proof string + CMS probe.
**D3 ✅ `noindex` left on in production**: an agency-specific need, absent everywhere else.
**D4 ✅ Dead-man heartbeat**, to watch a client's cron or backup: the *absence* of a signal raises
the alert. Equivalent to Kuma's push monitors, with a ready-to-copy URL.
**D5 ✅ Core Web Vitals**. LCP/CLS/INP through the PageSpeed API, one measurement a day, as a trend.
**D6 ✅ WordPress vulnerability watch**: core and plugin versions read from the HTML, cross-checked
against a CVE feed.
**D7 ◻︎ Scripted journey without a browser**: chain 3 requests (home → form → confirmation) with
token extraction, to validate a contact funnel without Playwright.

### Epic E: reporting to the client

**E1 ✅ Printable client report**: one screen per site, period of your choosing, ready to send as PDF.
**E2 ✅ CSV export of incidents**: SLA evidence.
**E3 ✅ Token-based public status page**: shareable without granting access.
**E4 ✅ Monthly report e-mailed to the client automatically.**

### Epic F: holding up at scale

**F1 ✅ 300 monitors on shared hosting**: SQL aggregation, a cap on expensive analyses per pass, purge.
**F2 ✅ Updates with no intervention**: the schema completes itself.
**F3 ◐ Wall view** for an agency screen, unauthenticated, dedicated token.
  *Partial: the token-based public status page exists (`p=status&token=`); the full-screen wall for
  an office television is still to build.*
**F4 ✅ Client read-only access.** `src/Client.php`, one token per client, isolation verified by the
test bench.
**F5 ✅ Renamed to UptimeEZ**: name, directory, database, catalogues, documentation.
**F6 ✅ *(August 2026)* Named accounts in the engine itself**, which **supersedes an earlier
decision**: this was previously scoped to the SaaS shell rather than the engine. It moved because a
single shared password has no way to tell who came in, and no way to withdraw one person's access.
Username, password, e-mail reset, and a sign-in journal that records failures alongside successes.
The single instance password **survives as a named emergency access**, because an instance whose
mail server is down still has to be reachable — and its use is recorded, since an emergency access
nobody knows was used is not an emergency access but a back door.

### Epic G: internationalisation

**G1 ✅ i18n, 10 languages, English by default.** The `I18n` engine uses the source French sentences
as translation keys (gettext-style msgids), so no technical keys in the templates and a forgotten
string stays readable. Cascading fallback language → English → source. Negotiation without ever
asking: `?lang=` → remembered choice → instance setting → `Accept-Language` → English. Plural rules
per family (three forms for Russian and Arabic). Right-to-left for Arabic and Urdu, with
measurements, URLs and code snippets kept left-to-right. Numbers formatted per language.
`bin/i18n-audit.php` measures coverage, lists untranslatable fragments and literals still outside
translation.
**G2 ✅ Catalogues.** 1 616 msgids, English complete, French complete as the source language (its
catalogue is deliberately empty — the msgids are already French). Eight other languages cover the
operating interface; long help texts fall back to English.

### Epic H: reducing cognitive load

**H1 ✅ Simple / Full switch.** One click in the bar changes everything: navigation, card contents,
monitor-record blocks, form scope. Simple is the default, deliberately. A hidden field is still
submitted with its value: switching modes cannot disable a monitor.
**H2 ✅ Contextual help.** A `?` on the seventeen notions that give people pause (proof string,
slowness threshold, tolerated CSS drop, retries, quiet hours, public token…). Keyboard-accessible
(`aria-describedby`, `role="tooltip"`), positioned in JavaScript to escape any parent that would
clip them.
**H3 ✅ Destructive beta test.** `bin/chaos.php`: 859 hostile requests playing a user who mistypes,
clicks everywhere and tries to break things. Contract verified: no 500, no PHP message in the page,
no input reflected back, database coherent at the end. Two real bugs found.
**H4** → merged into **C4** (the same need described twice, in two epics).

### Epic I *(August 2026)*: making the rules modular

**I1 ✅ One class per detection verdict.** `Runner::evaluate()` was 340 lines, 24 verdicts and
sixteen levels of nesting; the verdicts now live in `src/Regle/`, ten rules, and the nesting is down
to four. That number was the point: at sixteen levels nobody can tell which conditions hold when a
line runs, and that is where the invisible regressions came from.
**I2 ✅ The order of the rules is data**, declared in `Runner::REGLES`: changing it means moving one
line, and a test proves the loop honours the declaration rather than the source order.
**I3 ✅ The severity ceiling is structural.** `Verdict`'s constructor is private and `Verdict::pour()`
is the only way in, so a rule cannot return "down" on an appearance cause even by asking. It is no
longer a convention under watch; it is a door that does not exist.

*What the extraction found, which is the real return on it: three defects running in production that
no reading of the code would have shown, because they lived in duplication. The certificate verdicts
were written twice and the two copies had diverged, so a certificate with the wrong hostname went
quiet for six hours. The CSS verdicts repeated the same defect. And a slowness threshold of zero,
documented as "disabled", silently fell back to three seconds — the customer turned the alert off
and kept receiving it.*

---

## 3. Sources

*A link checker will report several of these as broken. They are not.* G2, Gartner, Capterra,
GetApp and The CTO Club sit behind bot protection and answer `403 Just a moment...` to anything
that is not a human in a browser; StackShare rate-limits and answers `429` if you check a batch of
links quickly. All of them were re-opened in a real browser on 2 August 2026 and all load. This is
worth writing down because the same false alarm has now cost time twice on this project.


- [UptimeRobot Reviews (2026): What Users Actually Say. Hyperping](https://hyperping.com/blog/uptimerobot-reviews)
- [Best Uptime Kuma Alternatives. Hyperping](https://hyperping.com/blog/best-uptime-kuma-alternatives)
- [Uptime Kuma vs UptimeRobot. StackShare](https://stackshare.io/stackups/uptime-kuma-vs-uptimerobot)
- [Site24x7 Reviews & Ratings. Gartner Peer Insights](https://www.gartner.com/reviews/product/manageengine-site24x7)
- [Site24x7 Reviews. Capterra](https://www.capterra.com/p/168192/Site24x7/reviews/)
- [Site24x7 Reviews. GetApp](https://www.getapp.com/it-management-software/a/site-24x7/reviews/)
- [Checkly Pros and Cons. G2](https://www.g2.com/products/checkly/reviews?qs=pros-and-cons)
- [Checkly Review: Monitoring as Code. Modern DataTools](https://www.modern-datatools.com/tools/checkly)
- [Zabbix Pros and Cons. G2](https://www.g2.com/products/zabbix/reviews?qs=pros-and-cons)
- [Zabbix Review 2026. The CTO Club](https://thectoclub.com/tools/zabbix-review/)
- [5 Common Sources of Alert Fatigue. New Relic](https://newrelic.com/blog/observability/alert-fatigue-sources)
- [SiteGuru Review: SEO Audits That Actually Tell You What to Fix. Revuary](https://revuary.com/reviews/siteguru-review/)
- [SiteGuru Reviews. G2](https://www.g2.com/products/siteguru-siteguru/reviews)
