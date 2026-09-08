---
title: Worker mode (FrankenPHP)
---

# Worker mode (FrankenPHP)

Under PHP-FPM every request starts from a blank process: the kernel boots, services are
constructed, the response is sent, everything is thrown away. Under a **worker runtime** —
FrankenPHP, RoadRunner, Swoole — the kernel boots once and the *same service instances*
answer every request that follows.

That single change turns one ordinary habit into a bug class:

> A property written while serving request *N* is still there for request *N+1*, and is
> served to whoever asks next — under whatever session, locale and permissions they happen
> to have.

ContentBlocks runs in worker mode. This page states the rule the packages hold themselves
to, how it is enforced, and what you have to do in your own code.

## The rule

> A class that keeps mutable state is either **resettable** or explicitly **declared** as
> not request-scoped.

**Resettable** means it implements `Symfony\Contracts\Service\ResetInterface`. Symfony
autoconfiguration tags such services `kernel.reset`, and `services_resetter` clears them on
`kernel.terminate` — the one hook every worker runtime calls between requests.

```php
use Symfony\Contracts\Service\ResetInterface;

final class MyCache implements ResetInterface
{
    /** @var array<string, string> */
    private array $rows = [];

    public function reset(): void
    {
        $this->rows = [];
    }
}
```

**Declared** means the state provably cannot leak, and someone wrote down why. Two shapes
qualify:

- **Not a shared service.** A value object, a per-operation tally, a Live Component — the
  instance itself does not outlive the request.
- **Container lifetime.** An index built from tagged services or configuration, identical
  for every request of the process. Memoizing a `tagged_iterator` once is exactly what a
  worker *should* do; clearing it would rebuild the same map.

Anything that touches a `Request`, a session, an entity or a locale fails the second test
and belongs in `ResetInterface`.

## What the packages do

| Service | Keeps | Why it is safe |
|---|---|---|
| `TranslationStore` (i18n) | translation rows prefetched for an area | `ResetInterface` |
| `FieldMetadataReader` (i18n) | field labels/widgets read off a block form | `ResetInterface` |
| `BlockTypeRegistry` (core) | the registered block types | written at instantiation by the compiler pass |
| `EnvelopeUpgradeChain` (core) | upgrade steps indexed by source format | constructor, services only |
| `BlockFormExtensionCollection` (core) | the tagged form extensions | constructor, services only |
| `IconRegistry`, `RichTextEditorRegistry` (kit) | icons / editor adapters by name | container lifetime, services only |
| `TranslationProviderRegistry` (i18n) | providers by name | container lifetime, services only |

Everything else is stateless, including all 17 kit blocks — which matters more than it
looks. A block type is a **shared service**: one instance answers `buildForm()` and
`getDefaultData()` for every block of that type, on every page, for every visitor.

## Enforcement

Two halves, deliberately different in kind.

### Static: `CrossRequestStateTest`

Each package carries one, and each fails on a class that keeps state without being either
resettable or declared. The scanner behind them ships in the core, so you can point it at
your own code:

```php
use ContentBlocks\Testing\CrossRequestStateScanner;

final class MyCrossRequestStateTest extends TestCase
{
    public function testNoUndeclaredCrossRequestState(): void
    {
        $scanner = new CrossRequestStateScanner(__DIR__ . '/../src', 'App\\');

        self::assertSame([], $scanner->unaccountedFor([
            \App\Something::class => 'Value object, never a shared service.',
        ]));
    }
}
```

`unaccountedFor()` returns the offenders; `staleDeclarations()` returns entries that no
longer describe anything, so the list cannot rot into folklore. It reflects over classes —
it never instantiates them.

### Runtime: the sandbox smoke check

Static analysis proves nothing about a kernel that is actually running, so the sandbox boots
under FrankenPHP and is interrogated:

```bash
cd apps/content-blocks-sandbox
./bin/worker-smoke.sh          # needs the `frankenphp` binary
```

It asserts, in order:

1. **Worker mode is really on** — the worker loop stamps `X-CB-Worker-Requests` with the
   number of requests the process has answered, and the counter must increase. Without this
   the whole script can pass while quietly running in classic mode, proving nothing.
2. **Seven public URLs render byte-identically across three interleaved passes**, with other
   locales and other pages served in between.
3. **The builder and the translation workbench still answer** on a reused kernel.
4. **An out-of-band content change is visible to the next request.** Identical bytes prove a
   cache is *stable*, not that it is *fresh*. So the script rewrites a translation directly
   in the database — behind the worker's back — and requires the very next response to show
   it. Deleting `TranslationStore::reset()` fails here and nowhere else.

## Running your own app in worker mode

The sandbox carries a worker script and a Caddy config you can copy:

- [`public/frankenphp-worker.php`](https://github.com/klehm/content-blocks-project/blob/main/apps/content-blocks-sandbox/public/frankenphp-worker.php)
- [`Caddyfile.worker`](https://github.com/klehm/content-blocks-project/blob/main/apps/content-blocks-sandbox/Caddyfile.worker)

```bash
CB_WORKER_FILE=$PWD/public/frankenphp-worker.php \
CB_WORKER_ROOT=$PWD/public \
    frankenphp run --config Caddyfile.worker
```

Three things bite when you set this up, all of them silent:

- **`$kernel->terminate()` is not optional.** It is what runs `services_resetter`. Drop it
  and every `kernel.reset` service — Symfony's own included — stops being cleared.
- **The worker file must be the script requests resolve to.** `php_server` rewrites to
  `index.php`; if your worker is some other file and you do not point `index` at it, the
  worker boots, no request ever reaches it, and everything is served in classic mode.
- **Give the site address an explicit `http://` scheme.** Caddy serves a bare `host:port`
  over HTTPS, and every plain request comes back `400 Bad Request`.

If you prefer a packaged runtime, `runtime/frankenphp-symfony` does the same job with a
`APP_RUNTIME` env var and no worker script. The one here is written out by hand so that what
happens between two requests is visible on the page.

## Writing worker-safe extension code

Everything below is ordinary good practice; a worker just makes the cost of ignoring it
visible.

- **Block types, decorators, resolvers and providers are shared.** Take what you need as
  method arguments; never stash "the block I am currently rendering" on `$this`.
- **Never hold an entity in a service property** across a request boundary. The
  `EntityManager` is cleared between requests and your reference becomes a detached object
  quietly serving last request's values. If you must cache entities — as the translation
  prefetch does — implement `ResetInterface`.
- **Read the request through `RequestStack`**, per call. Do not resolve it in the
  constructor.
- **A memoized cache keyed by something request-scoped is a leak**, however cheap it looks.
  Keyed by block type, it is usually fine — but if the form varies per user, it is not, which
  is why `FieldMetadataReader` resets.
- **Static properties and `static $x` inside functions are worse, not better.** They survive
  everything, including `ResetInterface`.
