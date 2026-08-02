## What this changes, and why

<!-- The why matters more than the what: the diff already says what. -->

## How you know it works

<!-- Which test covers it. If the change could regress, there should be a test that goes red
     without it -- try breaking it on purpose and check that the test notices. Several checks
     in this repository looked correct and could not fail. -->

- [ ] `php bin/selftest.php` green
- [ ] `php bin/e2e.php` green
- [ ] Falsified: the new test fails when the fix is reverted

## The three rules

- [ ] No new dependency, no build step, PHP 8.2 still enough
- [ ] Any new interface string goes through `t()`, and the msgid stays the French source sentence
- [ ] Comments explain why, not what

<!-- If the change touches a figure the README states (signatures, causes, CSS signals,
     languages), selftest will catch it: it compares the documentation against the code. -->
