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

## The fixture server needs two things PHP does not do by default

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

CI has caught a `Segmentation fault (core dumped)` from `php -S` mid-suite, after
which every remaining spec failed with `ECONNREFUSED` — thirty-odd red tests from
one crash.

The built-in server is explicitly not built for sustained concurrent load, and
`PHP_CLI_SERVER_WORKERS` is experimental. So the answer is to **survive a crash
rather than pretend it cannot happen**: the loop brings the server straight back,
and the retries absorb the handful of requests in flight when it went down.

Retries are one locally and two on CI. The goal is for them to rarely fire —
root-cause robustness (stable selectors, no fixed-position hovers) lives in the
specs, not in the retry count.

Ports are reserved: 8001 for the main fixture, 8002 for Encore, 8005 for the
FrankenPHP worker smoke test. A manual dev server must avoid them or a suite and
a person end up fighting over a port.

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
