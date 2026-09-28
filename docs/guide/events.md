---
title: Server-side events
---

# Server-side events

ContentBlocks dispatches Symfony events around four actions: publishing an
area, reverting it to its published version, saving a block and deleting one.
Each action has a pair of events:

- the **before** event is dispatched before anything is written, and a
  listener can **refuse** the action;
- the **after** event is dispatched once the change is in the database.

Listen to them to purge a cache, call a webhook, reindex a search engine, keep
an audit trail, or enforce an editorial rule, without decorating a package
service.

| Action | Before (refusable) | After | Properties |
|---|---|---|---|
| Publish | `BeforeContentAreaPublishEvent` | `AfterContentAreaPublishEvent` | `area`, `context` |
| Revert to published | `BeforeContentAreaDiscardEvent` | `AfterContentAreaDiscardEvent` | `area`, `context` |
| Save a block | `BeforeBlockSaveEvent` | `AfterBlockSaveEvent` | `block`, `area`; `data` on the before event |
| Delete a block | `BeforeBlockDeleteEvent` | `AfterBlockDeleteEvent` | `block`, `area` |

All of them live in `ContentBlocks\Event` and are named by their class, as
Symfony events usually are.

## Purging a cache on publish

```php
use ContentBlocks\Event\AfterContentAreaPublishEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class PurgePageCache
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly HttpCachePurger $purger,
    ) {
    }

    #[AsEventListener]
    public function __invoke(AfterContentAreaPublishEvent $event): void
    {
        $page = $this->pages->findOneBy(['contentArea' => $event->area]);
        if ($page !== null) {
            $this->purger->purge($page->getPublicUrl());
        }
    }
}
```

`$event->context` is the `PublishContext` the publish ran with, never `null`:
a call without one arrives as `PublishContext::everything()`. With
[`klehm/content-blocks-i18n`](./translation.md), `$event->context->locales`
says which languages were published along with the layout (`null` for all of
them), so a listener can purge only those pages.

## Refusing an action

Every before event extends `RefusableEvent`. Call `refuse()` with the reason
the editor will read:

```php
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RequireAPageTitle
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsEventListener]
    public function __invoke(BeforeContentAreaPublishEvent $event): void
    {
        $page = $this->pages->findOneBy(['contentArea' => $event->area]);
        if ($page !== null && $page->getTitle() === '') {
            $event->refuse($this->translator->trans('page.publish.needs_title'));
        }
    }
}
```

- **The reason is shown as is**, so translate it yourself. The builder shows
  it in its snackbar for a publish, a revert or a delete, and above the form
  for a block save.
- **Nothing is written** when an action is refused: no publish, no draft
  change, no undo step, and no after event.
- **Every listener still runs** after a refusal, and each can add its own
  reason. `isRefused()` tells a later listener that the action will not
  happen, and `getReasons()` lists why.

`BeforeBlockSaveEvent::$data` is the draft data about to be written, after the
block's form has validated it. Read it to refuse a value; it cannot be changed,
because the form is what decides which keys and values reach a block (see
[Security](./security.md)). A value every editor should be stopped from saving
belongs in the form, as a constraint; a refusal is for rules that depend on
something outside it: the page, the user, a workflow state.

A refused block save keeps the value in the editor's form, and the next edit
tries again. A refused delete leaves the block in the preview.

### When the caller is your own code

A refused publish or revert **throws** `ContentBlocks\Event\ActionRefusedException`
to whoever called `ContentAreaPublisherInterface`. Its `reasons` property lists
the reasons, and its message joins them. Catch it in a command or a controller
of your own that publishes:

```php
use ContentBlocks\Event\ActionRefusedException;

try {
    $publisher->publish($area);
} catch (ActionRefusedException $e) {
    $io->error($e->reasons);

    return Command::FAILURE;
}
```

The builder receives it as a `409` answer to the publish or discard request,
with `{"error": "refused", "message": "…", "reasons": ["…"]}`.

## Draft events and published events

Only `AfterContentAreaPublishEvent` means the public page has changed.

- **The block events are draft events.** A deleted block is flagged deleted,
  not removed: the published page still renders it until Publish, and a revert
  brings it back. React to them for what shows the draft (a preview cache, a
  "last edited" timestamp, an activity feed), never to purge a public page.
- **`AfterContentAreaDiscardEvent` leaves the public page as it was.** The
  draft goes back to the published state.
- **A block is saved many times in a row**, because the sidebar autosaves as
  the editor types, and each save dispatches both events. Keep the listeners
  cheap, and debounce anything expensive.

## When the events are dispatched

**Publish and revert, from any caller.** The four area events are dispatched
by the outermost decorator of `ContentAreaPublisherInterface`, so:

- they fire for the builder's buttons and for your own code or commands
  calling the publisher;
- the before event runs ahead of every other decorator, yours and the i18n
  package's included, and the after event runs once all of them are done:
  translations are committed when an after listener runs;
- they still fire if you replaced the publisher with your own implementation,
  because the decorator wraps whatever the interface points to.

A publish that throws dispatches no after event.

**Block events, from the builder only.** The save events come from the block's
sidebar form: typing, and reordering or duplicating a collection entry. The
delete events come from the builder's delete action, in the preview or the
navigator. The other flows that write blocks do not dispatch them:

- adding, moving or duplicating a block;
- paste, *Insert content*, section templates, import;
- undo and redo;
- deleting a whole section (its blocks are not flagged one by one).

These flows all end in the draft, so the publish events still catch them.

**After the flush.** An after event is dispatched once the change is in the
database. A listener that throws does not undo it: the action has happened,
and the exception surfaces as an error on that request. To stop an action,
refuse its before event rather than throwing.

## Compatibility

The event classes, their properties, `RefusableEvent` and
`ActionRefusedException` are covered by the
[backward-compatibility promise](./backward-compatibility.md). A later 1.x
version may add properties or new events, and will not remove or rename one.
