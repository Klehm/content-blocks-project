# Testing — the suites and their fixtures

Integrator-facing version: the *Tests* section of
[../../CLAUDE.md](../../CLAUDE.md).

## Two Playwright suites, two jobs

| Config | Fixture | Asks |
|---|---|---|
| `playwright.config.js` | `content-blocks-sandbox` — Symfony 7/8, AssetMapper | what the builder **does** |
| `playwright.encore.config.js` | `content-blocks-encore-sandbox` — Symfony 6.4, ORM 2, Encore | whether the packages **install and boot** |

The Encore suite stays deliberately small: anything that would pass identically
under either bundler belongs in the main suite. It exists because a boot bug —
the unconditional `asset_mapper` prepend — lived a long time without any test
seeing it. That sandbox therefore puts `symfony/asset-mapper` in its Composer
`conflict`, so the leg can never quietly re-derive onto the path already covered.

See [bundle-boot.md](bundle-boot.md#the-assetmapper-prepend) for the bug itself.

## The fixture server

Two servers, picked by `E2E_SERVER` in `playwright.server.js`, which both
Playwright configs share:

- **`php -S`**, the default, so a local run needs nothing installed.
- **php-fpm behind Caddy** (`E2E_SERVER=fpm`, `scripts/e2e-server.sh`), which
  is what CI runs.

### Why CI does not run `php -S`

`php -S` segfaulted now and then on CI (see the restart loop below): three
Playwright jobs out of ninety-six over two days, on block creation, the page
list and a Live Component alike, so not one route's bug. Each crash takes the
requests in flight with it, and two crashes a minute apart failed four specs
through both retries on the PostgreSQL leg of #92. It never reproduced locally.
php-fpm is built for that load.

Not FrankenPHP: its binary embeds its own PHP (8.4 today), so a job testing
PHP 8.3 would have served its pages from 8.4. The script starts the php-fpm of
the CLI's own version, with the CLI's `php.ini` and `conf.d`, so the browser
suite runs the exact PHP and extensions the job installed. Caddy is the
`caddy` binary if there is one, else FrankenPHP's embedded Caddy, whose PHP is
never used. Caddy's `php_fastcgi` sends a missing file to `index.php`, which
does what the router script below does for `php -S`.

Three traps the script handles, all met while writing it:

- **php-fpm starts a session of its own** (`setsid`). Playwright stops the
  server by killing its process group, which misses fpm. A watchdog, itself
  started with `setsid`, polls the script and stops fpm once it is gone; the
  `EXIT` trap covers only a clean exit.
- **fpm must not inherit Playwright's pipe.** A surviving process holding it
  open keeps Playwright waiting forever after the last test. fpm's stdio goes
  to its log file.
- **fpm cannot log to `/proc/self/fd/2`** when stderr is a pipe: it logs to a
  file that `tail -F` relays. `clear_env = no` lets `SANDBOX_SUFFIX` (and a
  CI `DATABASE_URL`) reach the application, which `php -S` does not: it reads
  `DATABASE_URL` from `.env.local`, not from the environment.

### What `php -S` needs that PHP does not do by default

**`PHP_CLI_SERVER_WORKERS`.** PHP's built-in server is single-process, so under
Playwright's parallel workers it serializes every request — and the preview
iframe pulls its HTML and assets concurrently. That starvation produced 30-second
timeouts that read as test failures. Forking workers is what makes concurrent
requests actually concurrent.

It only takes effect when the command **starts** the server; a reused,
already-running server keeps its own worker count.

**The router script.** Without it the built-in server treats any URL that looks
like a file as a static asset, so LiipImagine's lazy-cache URL
(`/media/cache/resolve/…/photo.png`) 404s instead of reaching the front
controller and no image variant is ever generated. nginx and Apache do not need
it.

### Why the server runs under a restart loop

The loop still wraps both servers; it is `php -S` that needed it.

CI has caught a `Segmentation fault (core dumped)` from `php -S` mid-suite, after
which every remaining spec failed with `ECONNREFUSED` — thirty-odd red tests from
one crash.

The built-in server is explicitly not built for sustained concurrent load, and
`PHP_CLI_SERVER_WORKERS` is experimental. So the answer is to **survive a crash
rather than pretend it cannot happen**: the loop brings the server straight back,
and the retries absorb the handful of requests in flight when it went down.

Two things the loop alone did not cover, both seen on CI (MySQL and PostgreSQL
legs alike, about thirty seconds into the run):

- **The workers outlive their parent.** They keep port 8001, so the restarted
  server failed with `Address already in use` until they went away. The loop
  now kills them (`pkill -f '^php -S 127.0.0.1:8001'`, anchored: unanchored,
  it matches the loop's own shell and kills it) before starting again.
- **A retry ran into the same outage.** The server stalls for several seconds
  before it crashes, and Playwright retries at once, so all three attempts of
  the same specs failed together. Every spec imports `test` from
  `helpers/test.js`, whose automatic fixture makes a retry, and only a retry,
  wait up to 30 s for the server to answer first.

The crash itself is not understood: no core dump has been captured, and it does
not reproduce locally.

Retries are one locally and two on CI. The goal is for them to rarely fire —
root-cause robustness (stable selectors, no fixed-position hovers) lives in the
specs, not in the retry count.

Ports are reserved: 8001 for the main fixture, 8002 for Encore, 8005 for the
FrankenPHP worker smoke test. A manual dev server must avoid them or a suite and
a person end up fighting over a port.

## The suite has its own data

Every spec creates its own page through `POST /page/create`, and nothing
deletes it. Run against the sandbox's own database, the suite left ~32,000
pages and 350 MB of uploads in the demo a developer opens on port 8000.

So both fixture servers run with `SANDBOX_SUFFIX=_e2e`, which the sandboxes
append to their database name (`dbname_suffix`) and to their upload directory
and prefix. Same code, same config, a second instance. It is emptied **before**
each run, not after: the last run's pages stay for whoever is reading a failure.

The reset is the first half of the `webServer` command (`bin/reset.sh` for the
main sandbox, `scripts/demo.sh reset encore` for Encore), not a `globalSetup`:
Playwright starts the server first, and on a fresh checkout the `_e2e` database
does not exist yet, so the readiness probe gets a 500 and the run times out.

The demos themselves are reset with `make reset [DEMO=…]` (same scripts, no
suffix, plus the seed). `reuseExistingServer` is still on, so a server already
on 8001 or 8002 that was started *without* the suffix would send the suite back
into a demo's data.

## Running two checkouts at once

Two git worktrees running Playwright together would share the ports and the
`_e2e` database: each run's reset empties the other's data mid-suite. Both
configs read three variables, with the defaults above when unset:

| Variable | Default | |
|---|---|---|
| `E2E_PORT` | `8001` | main fixture |
| `E2E_ENCORE_PORT` | `8002` | Encore fixture |
| `E2E_SUFFIX` | `_e2e` | `SANDBOX_SUFFIX` of both instances (database + uploads) |

```bash
E2E_PORT=8011 E2E_ENCORE_PORT=8012 E2E_SUFFIX=_e2e_media npm run test:e2e
```

Pick ports outside 8000–8005. A spec never spells an origin: it reads
`baseURL` (`test.info().project.use.baseURL` when it needs the string).

## The Vitest stub

`@symfony/ux-live-component` is supplied at runtime by the sandboxes' AssetMapper
importmap, not by npm, so Vitest is pointed at a stub. Without it, any controller
importing `getComponent` could not be unit-tested at all.

## Comments in tests

Tests keep the 80-column limit but **not** the two-prose-line budget, and the
hook encodes that. A comment on a test usually names the case it pins, which is
that comment's whole job; the duplication the budget exists to stop lives in the
code under test.

Rationale belonging to the *implementation* still belongs here in
`docs/internals/`, not restated in a test.
