# AI actions

The builder can ask a language model to rewrite a text field, describe an image for its `alt`, or write a section or a whole page from a prompt. **No model ships with the package.** Where a page's text is sent is your decision, not a dependency of a page builder. You register a provider, and until you do, the builder shows no AI button at all.

What every action has in common:

- **Model output is untrusted input.** A field action only *proposes* a value: the editor reads it, then replaces or cancels. Generated sections reach the draft through the same replay as a paste. Each block goes through its own form: a key the form does not declare is dropped, and a value it refuses falls back to the type's default.
- **Everything lands in the draft.** Nothing reaches the public page before Publish. A generation is one undo step (`Ctrl/Cmd-Z`), and *Revert to published* removes it.
- **Each action can be switched off on its own.** See [The actions](#the-actions).

## Writing a provider

Implement `ContentBlocks\Ai\AiProviderInterface`. Autoconfiguration registers it; there is nothing else to wire.

```php
use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiProviderInterface;
use ContentBlocks\Ai\AltTextRequest;
use ContentBlocks\Ai\ContentRequest;
use ContentBlocks\Ai\TextRequest;

final class MyModelProvider implements AiProviderInterface
{
    public function __construct(private readonly MyModelClient $client) {}

    public function supports(string $action): bool
    {
        // This model reads no images.
        return $action !== AiAction::ALT_TEXT;
    }

    public function rewriteText(TextRequest $request): string
    {
        $instruction = match ($request->action) {
            AiAction::SHORTEN => 'Shorten this text, same meaning.',
            AiAction::TONE => sprintf('Rewrite this text in a %s tone.', $request->tone),
            default => 'Rewrite this text so it reads better, same meaning.',
        };
        if ($request->maxLength !== null) {
            $instruction .= sprintf(' At most %d characters.', $request->maxLength);
        }
        if ($request->html) {
            $instruction .= ' Input and output are HTML.';
        }

        return $this->client->complete($instruction, $request->text, $request->locale);
    }

    public function describeImage(AltTextRequest $request): string
    {
        throw new \LogicException('Not supported.');
    }

    public function generateContent(ContentRequest $request): array
    {
        // The content schema says what the area accepts and how to write it.
        // See ./content-schema.md
        $json = $this->client->structured(
            system: json_encode([
                'toRevise' => $request->current,
                'blocks' => $request->schema->blocks,
                'palette' => $request->schema->palette,
                'sectionStyles' => $request->schema->sectionStyles,
                'layouts' => $request->schema->layouts,
                'examples' => $request->schema->examples,
                'pageSoFar' => $request->existing,
                'brief' => $request->brief,
                'maxSections' => $request->maxSections,
            ]),
            prompt: $request->prompt,
            jsonSchema: $request->schema->schema,
        );

        return json_decode($json, true) ?? [];
    }
}
```

What each request carries:

| Request | Fields |
|---|---|
| `TextRequest` | `action`, `text`, `locale`, `html`, `tone`, plus the field's context when the builder knows it: `blockType`, `field` (its path in the block data, `items.0.title`), `label`, `maxLength`, and the host's `brief` |
| `AltTextRequest` | `src` (as stored), `url` (absolute, for a remote model to fetch), `locale`, `blockType`, `context` (the caption or title beside the image), `brief` |
| `ContentRequest` | `action` (`add`, `edit_section` or `edit_page`), `prompt`, `schema` (the area's [content schema](./content-schema.md)), `locale`, `existing` (the draft sections, in the output shape), `maxSections`, `brief`, and for an edit `current`: the sections to revise (one for `edit_section`, all of them for `edit_page`) |

`generateContent()` returns a `{"sections": [...]}` document in the shape of `$request->schema->schema`. The package keeps at most `maxSections` sections (`ai.max_sections`, 8 by default), and one for an `edit_section` request. For `add`, the model decides how many sections the request needs: one for "an FAQ", several for "a page about our product".

For an edit, tell the model to change only what the editor asks and to answer with the revised sections, keeping everything else as it is (texts, image paths, colours, settings). The answer replaces `current`:
- `edit_section`: the revised section goes in the old one's place. The old one is deleted only once something valid came back, so a refused answer leaves it standing.
- `edit_page`: the revised page replaces the draft.

Both are draft deletes, so `Ctrl/Cmd-Z` or *Revert to published* bring the old version back. A revised section is made of new blocks, so its translations (with the translation package) are not carried over.

When a call fails, throw. The builder says *The AI service did not answer* and the error is logged. The exception's message is never sent to the browser, since it may hold a key or a URL.

Several providers can be registered; the first one answers. To choose among them, decorate one of them or register a single provider.

## The actions

| Action | Where | What it does |
|---|---|---|
| `rewrite` | ✦ beside a text field | Same meaning, better words |
| `shorten` | ✦ beside a text field | A shorter version |
| `tone` | ✦ beside a text field | One entry per configured tone |
| `alt_text` | ✦ beside an image's `alt` | Describes the image |
| `add` | topbar **Generate** | New content, one or more sections, after the selected section (or at the end of the page) |
| `edit_section` | **Generate**, or ✦ on a section's toolbar in the preview | Rewrites that section as asked; the new version takes its place |
| `edit_page` | topbar **Generate** | Rewrites the whole page from what is there |
| `apply` | `content-blocks:ai:apply` | Writes a document you generated yourself, see [below](#from-your-own-pipeline) |

An action is offered when it is switched on **and** your provider's `supports()` accepts it. Its endpoint answers `404` otherwise, so a disabled action is not there at all. All of them are on by default:

```yaml
# config/packages/content_blocks.yaml
content_blocks:
    ai:
        actions:
            tone: false        # no tone menu
            edit_page: false   # no rewriting of the whole page
        tones: [professional, friendly, persuasive, playful]   # empty switches `tone` off
        max_sections: 8        # the most sections one generation may write
```

`ai: false` switches every action off.

## Which fields get a button

The ✦ button goes on:

- **prose**: a text or textarea field tagged `cb_translatable` (the same tag the translation package reads), except a URL or an e-mail field;
- **an image's `alt`**: a field named `alt` beside an image upload field; the action describes that image;
- **HTML**: the kit's rich-text field, which declares itself HTML. TinyMCE and CKEditor take the new value through their own API.

A field type of your own decides with the `cb_ai` option: `'text'`, `'html'`, `'alt'`, or `false` for no button.

```php
$builder->add('slogan', TextType::class, ['cb_translatable' => true, 'cb_ai' => false]);
```

A type that must also work where the option is not defined can set `attr: {'data-cb-ai': 'off'}` instead. The kit's raw HTML block does: markup that runs as is is not a model's to rewrite.

A rich-text editor of your own takes a value set from outside by listening for `cb:field:set` on its textarea. It calls `preventDefault()`, puts `event.detail.value` into the editor, and syncs the textarea as it does after a keystroke.

## A brief from the host

A `ContentArea` knows nothing about the page it belongs to. To tell the model what the page is about (the product sheet, the audience, the tone of voice), alias `AiBriefProviderInterface`. Its text goes into every request as `brief`:

```yaml
# config/services.yaml
ContentBlocks\Ai\AiBriefProviderInterface:
    alias: App\Ai\ProductBriefProvider
```

```php
final class ProductBriefProvider implements AiBriefProviderInterface
{
    public function briefFor(ContentArea $area): ?string
    {
        $product = $this->products->findOneBy(['contentArea' => $area]);

        return $product ? sprintf('%s: %s. Price %s.', $product->getName(), $product->getPitch(), $product->getPrice()) : null;
    }
}
```

## From your own pipeline

Generation also works without the builder: an MCP server, a batch job, a script calling a model with the [content schema](./content-schema.md). The way back in is the same replay:

```bash
php bin/console content-blocks:schema --area=42 --only=schema > schema.json
# … your pipeline writes page.json …
php bin/console content-blocks:ai:apply 42 page.json             # appended to the draft
php bin/console content-blocks:ai:apply 42 page.json --replace   # in place of the draft
cat page.json | php bin/console content-blocks:ai:apply 42 - --after=17
```

The command reports skipped block types and reset fields, and writes to the draft only; an editor publishes from the builder. From PHP, `GeneratedContentWriter::write()` does the same and leaves the flush to you.

## The sandbox's demo provider

The main sandbox ships `App\Ai\DemoAiProvider`. It is deterministic and offline: a rewrite gets a ✦ in front, *shorten* keeps the first sentence, a description comes from the file name, and a page is a hero, cards, an FAQ and a call to action built from your prompt. It exists so the end-to-end suite can test the whole round trip with no model behind it. Copy its shape, not its writing.
