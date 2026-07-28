# Talk to Uptimer from an agent (MCP)

[← Operations](operations.md) · [Documentation](README.md)

Uptimer ships an MCP server, so Claude Code, Claude Desktop or any MCP client can ask it questions about your
portfolio and act on the answers. It is written in PHP like the rest of the project, with no dependency to
install.

---

## Setting it up

Add the server to your MCP client configuration:

```json
{
  "mcpServers": {
    "uptimer": {
      "command": "php",
      "args": ["/path/to/uptimer/bin/mcp.php"],
      "env": { "UPTIMER_CONFIG": "/path/to/uptimer/config.php" }
    }
  }
}
```

`UPTIMER_CONFIG` is only needed if your `config.php` is not in the project root. To allow the agent to act and
not just read, add `--write` to `args`.

Check it works before wiring it up:

```bash
printf '%s\n' \
  '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{}}}' \
  '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' | php bin/mcp.php
```

You should get a handshake naming the server, then the tool catalogue.

---

## What you can ask

The tools are designed around questions, not around database tables.

> *"What is broken on the client portfolio this morning?"*

Calls `tasks`. It returns the to-do list, most urgent first, with the cause in plain words, why it matters, what
to do, the raw technical reading, and the list of fixes available for that specific problem. Then it returns what
is about to break: expiring certificates, domains to renew, sites that have slowed by more than 50 %.

> *"Is everything fine?"*

Calls `status`. One answer: how many sites are down, degraded, up or paused, average uptime and response time
over 24 hours, and whether the collector has actually run.

> *"Why is the Deezer beta slow? Show me the trend over 30 days."*

Calls `monitor_detail` then `response_time_series`. The detail includes timings broken down by DNS, TLS and first
byte, the p95, the certificate, and the page-resource audit that catches a broken layout behind an HTTP 200.

> *"How much downtime did this client have last month?"*

Calls `incidents` with a period. It returns each outage with its cause and duration, plus the cumulative
downtime, which is the number an SLA conversation needs.

> *"Write me something I can send to the client."*

Calls `report`. Plain text, diagnosis, remedy, timeline, availability figures, ready to paste into an e-mail.

---

## The tools

Eight read-only tools, exposed by default:

| Tool | Purpose |
|---|---|
| `status` | Portfolio state in one call |
| `tasks` | The to-do list, plus what is about to break |
| `list_monitors` | Search and filter monitors. Accent-insensitive in any language |
| `monitor_detail` | One monitor in depth, including resources and automatic decisions |
| `incidents` | Outage history over a period, with total downtime |
| `report` | Ready-to-send report for one monitor |
| `response_time_series` | Time series to tell a spike from a trend |
| `security_target_check` | Whether an address would be refused before any request |

Four writing tools, only with `--write`:

| Tool | Purpose |
|---|---|
| `check_now` | Run a real check immediately, on one monitor or on everything due |
| `apply_fix` | Apply one of the remedies `tasks` listed: relearn the CSS reference, retune the slowness threshold, stop watching noindex, adopt the redirect target, snooze an hour, acknowledge |
| `set_enabled` | Pause or resume a monitor |
| `add_sites` | Add sites from a pasted list. Defaults to `dry_run`, which shows what would be created |

---

## Why read-only by default

An agent that is exploring should not be able to pause a monitor by accident, and an agent that misreads a
question should not be able to create forty monitors. So the mutating tools simply are not in the catalogue
unless you start the server with `--write`, and `add_sites` defaults to a dry run even then.

If you do enable writing, the server advertises it in the instructions it sends at handshake, so the agent knows
to show you a preview before creating anything.

## What it does not expose

There is no tool to read the configuration, to change settings, to delete a monitor, or to read the password
hash. The MCP surface is deliberately narrower than the web interface: it is meant for answering questions and
applying the fixes the tool itself proposed.
