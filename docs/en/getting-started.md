# Getting started

[← Installation](installation.md) · [Documentation](README.md) · [Monitors →](monitors.md)

Five minutes, and you are monitoring a portfolio properly.

---

## 1. Add your sites — paste, do not fill in forms

**+ Add** takes whatever you have.

```
exemple-client.fr
https://boutique-dupont.fr/
api.exemple.fr/health ; Internal API ; "status":"ok"
```

It also accepts a spreadsheet column, a client e-mail, or a paragraph of prose with domains buried in it — the
addresses get extracted, duplicates dropped, e-mail domains ignored, image and document filenames skipped.

You can be explicit when you want to: `url | name | proof string`, separated by `|`, `;` or a tab. Lines starting
with `#` are comments.

![Import screen with a pasted list of domains](../img/import.png)

### Nothing is created until you say so

The primary button is **See what will be created**. You get a table: one row per site, with the check rate it
will use, how many pages it will follow, the proof string it will watch, and whether the site already exists.

![Import preview listing sites, rates, pages and proof strings before creation](../img/import-preview.png)

Read it, then confirm. This is the step every other tool skips, and it is the one that stops you from creating
forty wrong monitors in one click.

### What happens next, on its own

On the following pass, for each site, Uptimer:

1. **fingerprints the technology** — WordPress, PrestaShop, Shopify, Drupal, Joomla, Wix, Astro, Next.js, Laravel…
2. **picks representative pages** from `robots.txt` → sitemap → internal links: one per family (contact, pricing,
   content), with cart and login pages deliberately excluded;
3. **derives the proof string** from the site's own content, never from an error page;
4. **sets the check rate** from each page's importance — pricing more often than legal notices;
5. **adds the CMS technical probes** where they mean something (on WordPress, the REST API, which really
   traverses the database);
6. **takes a first measurement**, so no card sits there saying "never checked".

Everything it decided is written in *What Uptimer decided on its own*, on the monitor page in Full mode.

---

## 2. Read the home screen

![Home screen: to-do list with cause, explanation, remedy and buttons](../img/today.png)

Top to bottom, and you stop as soon as it is green.

**The band** — one sentence: how many sites to bring back online, how many points to watch, average uptime,
response time, when the last pass ran. If the scheduled task has never run, it says so here, with a link to fix
it. That is the single most common setup mistake.

**To handle now** — one card per site, most urgent first. Each card carries:

- the **cause** in plain words ("The page layout is broken", not `CSS_BROKEN`);
- **who and since when**, plus the number of consecutive failures;
- **why it matters** — the sentence you can forward to a client;
- **the evidence** (Full mode): the raw technical reading;
- **what to do**, and the buttons that do it: check again, open the site, relearn the reference, raise the
  slowness threshold, adopt the current URL, copy the report, pause for an hour, acknowledge.

Nothing here navigates away. Every action shows a toast with **Undo**.

**Coming up** — nothing is broken yet, but it will be: a certificate expiring, a domain to renew, a site that
has slowed by more than 50 % over three days, a monitor never measured, a probe still awaiting setup.

**All clear** — everything else, folded onto one line with a 24-hour sparkline per site.

---

## 3. Choose your level of detail

The **Simple / Full** switch in the top bar changes the whole interface, not just one page.

| | Simple | Full |
|---|---|---|
| Navigation | Today, Monitors, Incidents, Settings | adds the Wall and the Report |
| Task cards | cause, why, what to do | adds the raw technical reading |
| Monitor page | state, key figures, chart, resources, incidents, settings | adds the measurement table, content events, sibling monitors, the decisions journal |
| Monitor form | name, rate, checks, alerts | adds access, maintenance window, per-monitor channels, User-Agent, TLS |

Simple is the default. It is a deliberate stance: most people, most days, need four things and not forty. The
switch is one click away and it is remembered.

---

## 4. Set up one alert channel

**Settings → Alerts.** Discord and Slack take a webhook URL and are working in thirty seconds. E-mail uses the
server's `mail()` function (fine on o2switch) or direct SMTP. The generic webhook posts JSON, for n8n, Make,
Teams or an SMS gateway.

Then press **Test**: a real message is sent through the real channel. A channel you have not tested is a channel
you do not have.

While you are there, three settings worth thirty seconds of your time:

- **Quiet hours** — for example `23:00-07:00`. "Needs watching" alerts are held; real outages always get through.
- **Warn before certificate expiry** — 14 days is a good default.
- **Notify on recovery** — so you know it is over without having to look.

---

## 5. Put the wall somewhere visible

`Wall` is built for a screen nobody interacts with: large cards, colour first, sites in trouble at the top,
automatic refresh every 30 seconds. Group by site or list every monitor; filter by group if you separate clients
from internal projects.

![Wall view with colour-coded cards per site](../img/wall.png)

---

## What to do next

- **[Monitors](monitors.md)** — one page per option, and when it is worth touching.
- **[Detection](detection.md)** — what "broken layout" really means, and why it does not raise false alarms.
- **[Alerts](alerts.md)** — routing, grouping, and how to keep alerts worth reading.
- **[Reports](reports.md)** — the client report and the public status page.
