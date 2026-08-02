# Security policy

## Reporting a vulnerability

Write to **contactez@coeurduweb.com** with `UptimeEZ` in the subject. Please do not open a public
issue: UptimeEZ is self-hosted, so a public report is a working exploit against every installation
that has not updated yet.

Tell us what you can reach, how, and what version you are on. A rough proof beats a polished one
that arrives a week later. You will get an acknowledgement within three working days, and we will
tell you what we intend to do and when, including if the answer is that we disagree it is a
vulnerability.

## What is in scope

The engine in this repository: `index.php`, `api.php`, `cron.php`, `install.php`, `beat.php` and
everything under `src/`, `views/` and `bin/`.

Things we consider serious, in roughly the order we would drop everything for:

- Reaching another instance's data. Each customer is a separate directory and a separate database,
  and that boundary is the product.
- Bypassing the sign-in, the bridge token or the CSRF protection.
- Reading or writing files outside the installation.
- SQL or command injection anywhere, including through a monitored site's own response — the engine
  fetches arbitrary URLs and parses what comes back, which is the widest attack surface it has.
- Making the engine fetch a private address on behalf of an attacker. `security.block_private_ranges`
  exists for that; a way around it is a real finding.
- A client's read-only link showing anything belonging to another client.

## What is not

- Anything requiring an already-authenticated operator account. An operator can already change every
  setting; that is not a privilege escalation, it is the job.
- Missing hardening headers on the public status page, where there is nothing to protect.
- Denial of service by sheer volume against your own installation.
- Reports from an automated scanner with no reproduction. We read them, but a scanner's opinion is
  not a finding.

## Supported versions

The latest release on the `main` branch. There is no long-term support branch: the engine has no
dependencies and updates by replacing the files, so staying current costs a file copy.

## Disclosure

We will credit you unless you prefer otherwise, publish what the flaw allowed once a fix is out, and
say plainly which versions were affected. A security note that hides the impact protects nobody
except us.
