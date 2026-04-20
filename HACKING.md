# Hacking on WooCommerce Conflict Doctor

**Target: working local environment in under 10 minutes.**

## Prerequisites

- [Node.js](https://nodejs.org/) 18+ and npm
- [PHP](https://php.net/) 8.1+
- [Composer](https://getcomposer.org/)
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (running)

## Setup

```bash
git clone https://github.com/kingsleyinfo/woocommerce-conflict-doctor.git
cd woocommerce-conflict-doctor

npm install          # installs @wordpress/env and Playwright
composer install     # installs PHPUnit + brain/monkey

npm run start        # boots wp-env (WP 6.5, PHP 8.1, WooCommerce 9.x)
npm run setup        # creates test admin user
```

Visit `http://localhost:8888/wp-admin/` — user: `admin`, password: `password`.

The plugin is activated automatically by wp-env (it's mapped as `.` in `.wp-env.json`).

Navigate to **WooCommerce → Conflict Doctor** to see the wizard.

## Running tests

```bash
# Unit tests (PHPUnit + brain/monkey, no Docker needed — runs in < 5 seconds)
composer test

# E2E tests (Playwright + wp-env, Docker must be running)
npm run test:e2e
```

## Resetting between test runs

The MU-plugin persists across wp-env restarts because Docker volumes are preserved.
Clear it before re-testing a fresh install:

```bash
npm run clean
```

## File structure

```
woocommerce-conflict-doctor.php   Plugin bootstrap
includes/
  wcd-constants.php               All WCD_* constants
  class-troubleshoot-mode.php     Session CRUD + MU-plugin lifecycle
  class-wizard.php                AJAX endpoints + wizard admin page
mu-plugin/
  wcd-loader.php                  SOURCE for the MU-plugin (copied on first run)
                                  Uses define() constants only — no PHP classes
assets/
  wizard.js                       Vanilla JS wizard state machine
  wizard.css                      Wizard styles
tests/
  unit/                           PHPUnit + brain/monkey (no WP stack)
  e2e/                            Playwright tests
uninstall.php                     Removes MU-plugin + all wcd_* options
```

## Key constraints to keep in mind

- `mu-plugin/wcd-loader.php` must use **only `define()` constants and WordPress core
  functions** — it runs before any plugin class is loaded.
- All `wcd_session_*` options must use `autoload = false` — session data should never
  be pulled into the global WP options cache on every page request.
- `binary_search_next_round()` and `sequential_next_suspect()` are **pure functions**
  (no WP calls, no side effects) so they can be unit-tested without wp-env.
- Never disable WooCommerce, this plugin, or anything on the allowlist in `wcd-constants.php`.
