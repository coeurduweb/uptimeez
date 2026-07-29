# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem. Use GitHub's private reporting
(*Security → Report a vulnerability*) on this repository, or write to the address in the commit history.

Include: what you did, what happened, and what you expected. A proof of concept is welcome but not required , 
a clear description is enough to get started.

You will get a first answer within a few days. Fixes for anything exploitable are released as soon as they are
tested; the fix commit credits the reporter unless you prefer otherwise.

## What is in scope

Anything in this repository: the web interface, the collector, the CLI scripts, the installer, the public status
page, the heartbeat endpoint.

## Verifying a claim yourself

The project ships its own audit, so you can check most of the surface before reporting:

```bash
php bin/security.php              # three depths, OWASP Top 10 references
php bin/security.php --niveau=1   # configuration, secrets, exposed surface
php bin/security.php --niveau=2   # active OWASP tests on an isolated instance
php bin/security.php --niveau=3   # SSRF, XXE, bombs, timing, formula injection
php bin/chaos.php                 # 859 hostile requests; nothing may break
php bin/infra.php                 # a broken install must not leak paths or credentials
```

Levels 2 and 3 build an isolated instance and a deliberately hostile local site. Nothing touches your
installation and nothing leaves your machine.

**A failure of Uptimeez itself discloses nothing publicly.** When the storage layer is down, the status page, the
client space and the heartbeat endpoint answer 503 with a neutral sentence: no file path, no database engine, no
database user name, and no PHP stack trace. The cause, the remedy and the trace are shown to the signed-in
operator only, and written to `data/erreurs.log`. `bin/infra.php` provokes eight real infrastructure failures and
asserts the non-disclosure for each one.

## Known and accepted design decisions

These are deliberate, documented, and not vulnerabilities : but you should know about them.

**The collector fetches the URLs you give it.** That is the product. Non-HTTP schemes are rejected at input, and
curl is restricted to HTTP/HTTPS on requests *and* redirects, so a monitored site cannot make the collector read
a local file. Private and loopback addresses are **allowed by default**, because monitoring an intranet or a
staging site on `192.168.x` is a legitimate use. If your interface is reachable by people you do not fully
trust, turn the guard on:

```php
'security' => [ 'block_private_ranges' => true ],
```

**Single account.** There is one password, no user management. The interface is meant for the person who
administers the monitored sites. Multi-user with read-only client access is on the roadmap.

**Verdict messages stored in the database are written in the source language** when the collector runs, and
translated at display only when they match a known message exactly. This is a completeness limitation, not a
security one.

## Supported versions

The `main` branch is the supported version. There is no long-term support branch yet.
