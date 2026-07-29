# Agency mode: one link per client

**A client is a name and a link. The link opens a page where they see their sites, and nothing else.**

[← Documentation](README.md) · [Version française](../fr/mode-agence.md)

---

## The problem

You monitor thirty sites belonging to twelve people. Each one wants to know whether theirs is fine. None of them
has any business seeing the other twenty-nine.

The tools on the market answer this with user accounts, roles and permissions: three configuration screens, one
password to hand out per client, and a "I lost my password" on a Sunday evening.

Uptimeez answers differently. You create a client, tick their sites, copy their link. Done.

![Clients screen](../img/clients.png)

---

## What the client sees

One page, no account, no password:

- a band at the top saying **everything is working** or **one of your sites is not responding**;
- one block per site: state, last 24 hours as a curve, 30-day uptime;
- recent outages, with date, duration, and whether service is back.

![Client space](../img/client-space.png)

What they do not see: your other clients, your settings, your thresholds, your automatic decisions, the name of
your tooling. There is not a single button on that page, and the word "monitor" never appears.

It reads well on a phone, because that is where a client opens a link received by e-mail.

---

## The link is the password

Worth stating plainly: the link carries a 32-character hexadecimal token drawn at random. Whoever holds it can
see the page. It is the same trade-off as the public status page, and it is deliberate: a client who has to create
an account to check whether their site works will not do it.

What keeps that trade-off defensible:

| Measure | Effect |
|---|---|
| Token from `random_bytes` | 128 bits, unguessable, never derived from the client's name |
| Page sent `noindex, nofollow, noarchive` | A crawler that stumbles on the link will not publish it |
| `Referrer-Policy: no-referrer` | Sites linked from the page never receive the token |
| `Cache-Control: private, no-store` | No copy in a shared cache |
| No write path reachable | Actions live behind authentication, not behind the token |
| One-click link change | A link that travelled too far is dead immediately |
| Access closed without losing anything | The link returns not-found, the history stays intact |

An unknown link, a malformed link and a closed link all return **exactly the same response**: no amount of probing
reveals that a client exists.

Partitioning, finally, is not a matter of display. Every read in the space filters on `client_id`, and no
identifier taken from the URL ever enters those queries: appending `&client_id=7` or `&site=3` to the link changes
nothing about what is shown. The test suites check this, hostile tokens included.

---

## Setting it up

### Create a client

**Clients** screen → *Add a client*. A name is enough. The contact address is optional and only feeds the monthly
report.

### Attach their sites

In the client's *Settings* block, tick their sites. A site belongs to exactly one client: those already taken
appear locked, with a note saying they are attached elsewhere. Unticking a site simply leaves it without a client,
it does not disappear.

### If your sites are already grouped

The importer lets you enter a group. If you used it, the **Reuse existing groups** button creates one client per
group and attaches the sites in one go. Nothing is overwritten: a site already attached stays where it is, a
client with the same name is reused rather than duplicated, and pressing the button again creates no duplicates.

### Send the link

The *Link to send to the client* field selects with one click. Fill in **Settings → Address of this installation**
first, otherwise the link will start with `https://votre-adresse-uptimeez`.

---

## What it changes elsewhere

**The monthly report inherits the client's address.** A site with no recipient of its own uses its client's
contact address. That is what saves you from retyping the same address on their eight sites. The site's own
setting, when present, always wins. See [Reports](reports.md).

**The MCP agent can answer per client.** The `list_clients` tool returns clients, their sites, their state and
whether they still open their space, which makes "which client should I call first?" answerable. The link itself
is never returned: it opens a page without authentication, so it does not belong in a conversation. See
[MCP server](mcp.md).

**The Clients tab only appears once a client exists.** With no client created, the screen is absent from the nav
bar: it is not a feature to put up with when you monitor your own sites.

---

## Tracking usage

The client list shows, for each one, the last time their space was opened and how many times. That is useful in
both directions: a client who opens their space every week does not need a phone call, and a client who never
opened it probably did not understand what the link was for.

---

## Deleting a client

Deleting removes the client and detaches their sites. **Sites, monitors and the whole history are kept.** A
deleted client must not take thirty monitors with it: that cannot be undone, whereas recreating a client takes ten
seconds.

---

## What agency mode does not do

- **No user accounts.** There is one password, yours. If several people at the agency administer the tool, they
  share it. That is a choice, not an oversight: user management costs three screens and a full lifecycle for a
  need most agencies do not have.
- **The client cannot trigger anything.** No re-check, no acknowledging an alert, no requesting a report. The page
  is read, not driven.
- **No client logo, no dedicated subdomain.** The space carries whatever name you gave the installation. Full
  white-labelling would be a different feature.

---

## Troubleshooting

**The link shows "Lien invalide ou expiré".** Three causes, one deliberate answer: the token is wrong, it was
changed, or access is closed. Check the *Access open* switch and copy the link again from the Clients screen.

**The link starts with `https://votre-adresse-uptimeez`.** The installation address is not set:
**Settings → Application and access → Address of this installation**.

**A client says they can see a site that is not theirs.** That is impossible by construction, but it is an issue
to open immediately with a screenshot: it would be the one flaw that really matters here.

**A site appears in no space at all.** It is attached to no client. The bottom of the Clients screen says so and
gives the count.

---

[← Documentation](README.md) · [Reports](reports.md) · [MCP server](mcp.md) · [Security watch](security-watch.md)
