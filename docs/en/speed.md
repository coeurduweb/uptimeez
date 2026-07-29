# Speed as visitors feel it

**Uptimer does not guess your Core Web Vitals. It measures what it can measure, reads from your pages what
degrades them, and tells you which of the two you are looking at.**

[← Documentation](README.md) · [Version française](../fr/vitesse.md)

---

## The problem, stated plainly

Google's three official metrics (LCP, INP, CLS) come from real Chrome browsers, on real visitors. There is no
honest calculation that replaces them without a browser. A PHP tool that displayed "LCP: 2.1 s" without ever
launching Chrome would be inventing a number, and you would believe it.

So Uptimer does two distinct things, with two distinct vocabularies:

| What it is | What it is worth | Key needed |
|---|---|---|
| **Field measurements**: LCP, INP, CLS on your real visitors, via the Chrome UX Report | These are the official figures, the ones that count for ranking | Yes, free |
| **Causes read from the page**: measured response time, render-blocking files, top-of-page image, images without dimensions, fonts, third-party scripts | These are probable causes, and they are what you act on | No |

The two are never mixed in one sentence, and the screen says so in as many words: "these are probable causes,
nothing here is a browser measurement".

![Perceived speed](../img/vitals.png)

---

## What works with nothing configured

### Server response time

Measured by Uptimer on every check, in milliseconds, over the real network. It is a measurement, not an estimate,
and it is the floor for everything else: **LCP will never beat the server response time.** The target is 800 ms;
past 1.8 s it is poor.

### What blocks the first paint

The resource audit already downloads every stylesheet and script on the page, with their exact weight. Uptimer
derives from that what actually blocks rendering:

- a stylesheet in the head blocks rendering, by construction;
- a stylesheet with `media="print"` does not, and is therefore not counted;
- a script without `defer` or `async` in the head blocks HTML parsing;
- a script at the end of the body blocks nothing.

Weight is counted per kind: "three stylesheets weigh 203 kB" does not quietly include the JavaScript.

### The top-of-page image

It is almost always what LCP measures. Uptimer identifies the first image in the body that is neither an icon, nor
a logo, nor a tracking pixel, then makes **a single HEAD request** on it to learn its real weight, which is
nowhere to be found in the HTML.

Two very common defects are then reported:

- **the image is `loading="lazy"`**: the browser loads it last, when it is exactly what the visitor is waiting for.
  It is the most frequent mistake and the easiest to fix;
- **the image is over 250 kB**: on a phone over 4G, this single file adds more than a second.

### Layout shifts

- **Images with no `width` or `height`**: the browser cannot reserve the space, so text jumps when the image
  arrives. An image carrying an inline `aspect-ratio` is not counted: the space is reserved.
- **Fonts without `font-display`**: text stays invisible while the font downloads, then appears all at once.

### Third-party scripts

The number of third-party domains loading script in the head. A subdomain of the monitored site is not a third
party. Past four, it is reported: each one adds a DNS lookup, a TLS handshake and main-thread work, which delays
the response to the first click.

**Every cause carries its fix.** A finding with no course of action is just a reproach.

---

## Field measurements, with a key

### Create the key

1. Open the [Google Cloud console](https://console.cloud.google.com/), create a project or pick one.
2. Enable the **Chrome UX Report** API.
3. Create an **API key** and copy it.
4. Paste it into **Settings → Speed as visitors feel it**.

It is free, and the key only grants access to public aggregated audience data. No access to your sites, no access
to your Search Console.

### What it adds

On each monitor's page, the three official metrics with their verdict:

| Metric | "Good" threshold | What the visitor experiences |
|---|---|---|
| Largest contentful paint (LCP) | 2.5 s | The moment the page looks loaded |
| Interaction to next paint (INP) | 200 ms | The delay between their gesture and the visible response |
| Layout stability (CLS) | 0.1 | How much the content jumps while loading |

**The overall verdict takes the worst of the three.** That is Google's rule, and the only honest one: a page whose
layout jumps around is not "generally fine" because its LCP is good.

### When there is no data

The Chrome UX Report requires a sufficient sample. A rarely visited page is not in it. In that case Uptimer queries
the site's origin and says so explicitly: "this page does not have enough traffic to be measured on its own, the
figures cover the whole site". If the origin has no data either, no figure is displayed. That is an answer, not a
failure.

A missing metric stays missing: it never becomes a zero, which would read as a perfect score.

### What it costs

One lookup per page per day, cached for 24 hours, capped at thirty per maintenance pass. The service's free quota
is far above what an agency portfolio consumes.

---

## Settings

Under **Settings → Speed as visitors feel it**:

| Setting | Effect |
|---|---|
| Fetch field measurements | Switches off Chrome UX Report lookups. The local analysis continues. |
| Chrome UX Report key | Empty, only the local analysis works. |
| Reference device | Phone or desktop. Phone is the right default: that is where problems show. |

From the command line:

```bash
php cron.php --vitals    # force a measurement pass without waiting for 3 a.m.
```

In `config.php`:

```php
'vitals' => [
    'enabled'     => true,
    'crux_key'    => '',        // empty = local analysis only
    'form_factor' => 'PHONE',   // or DESKTOP
    'timeout_sec' => 10,
],
```

---

## Where it shows up

**On a monitor's page**, a "Speed as visitors feel it" panel: field measurements if they exist, the measured
response time, what blocks the first paint, the top-of-page image, then the list of causes with their fixes, ranked
by impact. Severity reads off the edge of each cause, without reading the text.

**On the home screen**, a page with a poor field verdict becomes a task, carrying the most probable cause found in
the HTML. A number without a cause makes nobody act.

**From an agent**, the `web_vitals` MCP tool returns both layers as JSON, kept separate, with the thresholds
applied. See [MCP server](mcp.md).

---

## What this feature does not do

- **It does not launch a browser.** No headless Chrome, no Lighthouse, no Node. That is what lets Uptimer run on
  shared hosting, and it is also what limits what it can measure. The trade-off is deliberate.
- **It does not replace PageSpeed Insights.** To audit one page in depth before a redesign, run Lighthouse.
  Uptimer watches continuously and warns you when things degrade, which Lighthouse does not do.
- **It does not guess the exact LCP element.** Without a browser, there is no way to know which element covers the
  most screen. Uptimer takes the first large top-of-page image, which is the right answer on the vast majority of
  pages, and it writes "very probably" rather than "this is".

---

## Troubleshooting

**No speed panel on a monitor's page.** The analysis runs during the resource audit, at most every fifteen minutes
per monitor. The *Check now* button forces it. It also requires resource checking to be enabled on that monitor.

**No field measurements despite the key.** Three possible causes: the key is invalid, the Chrome UX Report API is
not enabled on the Google Cloud project, or neither the page nor its origin has enough traffic. The word
"measured" only appears when a response was obtained, so its absence is information.

**The top-of-page image weight stays empty.** The server returns no `Content-Length` header for that image, or the
HEAD request is refused. Uptimer does not invent the weight in that case.

---

[← Documentation](README.md) · [Detection](detection.md) · [Monitors](monitors.md) · [MCP server](mcp.md)
