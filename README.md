# Symfony 8.2 sudo mode: re-authentication, actually run

Runnable demo for the article **"Sudo Mode in Symfony 8.2"** by [Michał Woliński](https://wolinski.com).

- **Article:** https://wolinski.com/blog/symfony-8-2-sudo-mode.html
- **Author:** https://wolinski.com

It answers, by running them, the questions that a blog post about this feature
usually leaves open: what exactly the two new attributes mean, when the step-up
is offered and when it is not, where the user lands afterwards, and what a
stateless firewall can do with any of it.

## The version this runs on

Symfony 8.2 is not released as I write this, so `composer.json` pins the
`8.2.x-dev` branches of `framework-bundle` and `security-bundle` (and lets them
pull their own dependencies), with `minimum-stability: dev`. Once 8.2 is out,
replace those two constraints with `^8.2` and drop `minimum-stability`.

The two branches the output below was produced from:

```
symfony/framework-bundle  8.2.x-dev 9dc925b
symfony/security-bundle   8.2.x-dev a40c203
symfony/security-core     8.2.x-dev 1370ce5
symfony/security-http     8.2.x-dev 6ec1693
```

## Running it

```
composer install
php run-checks.php
```

It prints each check and every HTTP request it makes, and exits non-zero if any
check fails. The captured output of a full run is in `checks.log`.

## Why there is a clock in here

The whole feature is about elapsed time, so the demo cannot wait for it. `clock`
is aliased to `App\Clock\TestClock`, which is the service `security.authentication.trust_resolver`
reads when it asks what "now" is. That alias is the only thing needed to move
time: the trust resolver class itself does not have to be replaced.

`TestClock` keeps its timestamp in a file rather than a property, because the
kernel is rebooted between requests and an in-memory value would reset on every
one of them.

## What the run establishes

Read from the output, not from the documentation:

- the default windows are 7200 and 300 seconds, and they are checked with `<=`,
  so a proof exactly 7200 seconds old still counts as recent;
- a password three hours ago plus a hardware key one minute ago passes both
  checks, because the decision uses `max()` over the proofs rather than any
  single method;
- a remember-me token carrying a brand new proof still fails both checks,
  because the resolver first requires the token to be full-fledged;
- ten minutes after logging in, the recent action runs and the very recent one
  is redirected to the confirm page, with `_security.re_authentication_attribute`
  naming the attribute that was denied;
- after re-authenticating, a **safe** method is replayed: a denied `GET` returns
  the user to the page they asked for. A denied `POST` is **not** replayed, and
  the user lands on the default target path instead, because the target path is
  only saved when `$request->isMethodSafe()`. The action has to be submitted
  again;
- a security rule naming two attributes is denied as a whole, so a stale user
  gets a bare `403` with no step-up offered — while the same user, in the same
  stale state, gets a step-up on a rule naming one attribute;
- two separate `#[IsGranted]` attributes, one for freshness and one for a role,
  **do** offer the step-up, because the attributes are decided one by one.

## Layout

```
src/Controller/AccountController.php     the guarded endpoints
src/Security/ConfirmPasswordEntryPoint.php  the entry point from the docs
src/Clock/TestClock.php                  time you can move
config/packages/security.yaml            windows, firewall, entry point
run-checks.php                           the whole suite
checks.log                               captured output
```

## About

Written by [Michał Woliński](https://wolinski.com) — a systems architect
working on backend infrastructure, distributed systems and production AI. The
article this accompanies is
[Sudo Mode in Symfony 8.2](https://wolinski.com/blog/symfony-8-2-sudo-mode.html).

If you reproduce something different on your versions, an issue here is the
right place for it.
