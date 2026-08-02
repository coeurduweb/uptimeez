# Contributing to UptimeEZ

Issues and pull requests are welcome. This page says what the project needs, what it refuses, and
how to run the tests, so you do not have to guess any of it from the code.

## What is most useful

**Detection signatures.** A CMS or framework whose failure mode we do not recognise yet makes an
excellent issue. Paste the HTML the broken site actually served: the signature is written against
real output, never against a description of it.

**A check that got it wrong.** A false positive is worth more than a feature request. Say what the
tool claimed, what the page really was, and attach the content that produced the verdict. Five of
those on 2 August 2026 turned out to be five distinct defects, and each one now has a permanent
fixture in `bin/selftest.php`.

**Corrections to the comparison table.** It states what UptimeRobot, Uptime Kuma, Checkly, Site24x7,
Zabbix and New Relic do. They change, and we get it wrong in both directions: on 2 August we found
we had *understated* two competitors' free tiers. If a cell is wrong, point at the vendor's own page
and it gets fixed.

**Translations.** Nine catalogues cover the operating interface; longer help texts fall back to
English. `php bin/i18n-audit.php --manquants=xx` lists exactly what a language is missing.

## Three rules that will not bend

**No dependency, no build step.** The promise is that UptimeEZ runs on plain shared hosting: upload
by FTP, open a page, done. The day it needs `composer install` or a bundler, it has lost the thing
that distinguishes it. PHP 8.2 is the floor, and it is enforced in `src/bootstrap.php` — shared
hosts lag, and that is precisely who this is for.

**The translation keys are the French source sentences.** `t('Certificat SSL expiré')` is the msgid,
gettext-style, and `lang/en.php` maps it to English. That is why there are no technical keys in the
templates and why a forgotten string stays readable. It also means you should not translate msgids
into English in a patch: it would break the ten catalogues one file at a time.

**A test for anything that could regress**, and comments that explain *why* rather than restate the
code. The interesting comment is the one that says what was tried, what broke, and what the
alternative cost — not the one that says a loop iterates.

## Running the tests

Everything runs without a network, a browser or a database unless the name says otherwise:

```bash
php bin/selftest.php     # detection logic, offline, the one to run first
php bin/e2e.php          # full interface journey over real HTTP, isolated instance
php bin/e2e.php --real   # the same, plus checks against a live public site
php bin/security.php     # injection, sessions, permissions, headers
php bin/chaos.php        # 859 hostile requests: no 500, no PHP message, coherent database
php bin/i18n-audit.php   # translation coverage and untranslatable fragments
php bin/mysql.php        # the same schema on MySQL rather than SQLite
node bin/e2e-browser.mjs # real Chromium: rendering, keyboard, mobile, contrast
```

A pull request is expected to leave `selftest` and `e2e` green. If you change anything the README
counts — signatures, explained causes, CSS signals, languages — `selftest` will tell you, because it
compares the documentation against the code rather than trusting either.

## One habit worth borrowing

**Falsify your own test before believing it.** Break the thing it is supposed to catch and check
that it goes red. Several checks in this repository looked correct and could not fail: one compared
two identical inputs, another accepted a class that every screen carries. A check that cannot fail
is worse than no check, because it reports success.

## Reporting a security problem

See [SECURITY.md](SECURITY.md). Please do not open a public issue for it.

## Licence

MIT. By contributing you agree your work is published under it.
