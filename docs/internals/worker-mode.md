# Worker mode — what may be kept on a service

Integrator-facing version: [../guide/worker-mode.md](../guide/worker-mode.md).

## The rule

Under a worker runtime (FrankenPHP, RoadRunner, Swoole) the container boots once
and every service instance is reused by every subsequent request. **A property
written while serving one request is handed to the next visitor**, under whatever
session, locale and permissions that one carries.

> A class that keeps mutable state is either **resettable** or explicitly
> **declared** as not request-scoped.

*Resettable* means `ResetInterface`: autoconfiguration tags it `kernel.reset` and
Symfony's `services_resetter` clears it on `kernel.terminate`, the one hook every
worker runtime calls.

*Declared* means listed by the caller with the reason it cannot leak — a value
object, a compile-time-only bundle class, an index of tagged services identical
for every request. **Writing that reason down is most of the value**: it is where
"this cache is fine" has to become a sentence someone can disagree with.

## The scanner

`CrossRequestStateScanner` ships in core `src/`, not in `tests/`, so a host can
point it at its own code. It reflects over classes; it never instantiates them.

```php
$scanner = new CrossRequestStateScanner(__DIR__ . '/../../src', 'App\\');
self::assertSame([], $scanner->unaccountedFor([
    Foo::class => 'Value object, never a shared service.',
]));
```

- `scan()` returns every class declaring at least one mutable property.
  **Static properties count** — they are worse than instance state, not better.
  Inherited properties are reported against the class that declares them.
- `unaccountedFor()` returns the failures: stateful, neither resettable nor
  declared. Adding a service with an undeclared cache fails the suite instead of
  producing a bug that reproduces on the third page view in production.
- `staleDeclarations()` returns entries that no longer describe anything — a
  class deleted, renamed, or since stripped of its state. Dropping them is what
  keeps the allowlist from turning into folklore.

Doctrine entities are skipped by default: they are hydrated per request and never
shared.

## Why the runtime half exists too

The static scanner cannot see a cache that is populated correctly and then read
staleley. `bin/worker-smoke.sh` boots the sandbox under a real FrankenPHP worker
and asserts, among other things, that **a content change written straight to the
database is visible to the very next request**.

That is the only assertion that catches a stale cache. Identical bytes across
passes prove a cache is *stable*, not *fresh* — removing `TranslationStore::reset()`
fails there and nowhere else.

The script also asserts the request counter increases, because otherwise it
silently ran in classic mode and proved nothing.
