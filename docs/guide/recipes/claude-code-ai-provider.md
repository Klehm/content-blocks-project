---
title: Claude Code as an AI provider, in development
---

# Claude Code as an AI provider (development only)

The [AI actions](../ai.md) need a provider, and none ships with the package. For a hosted site that provider calls a model API with your own key. Before launch, though, developers often have to write a site's first pages, and they may already have [Claude Code](https://claude.com/claude-code) installed and logged in. This recipe wires the builder's AI actions to it: every rewrite, image description or generation runs `claude -p` under the developer's own login, with no API key in the application.

::: warning Development only
This provider runs a command-line process per request, under the login of whoever started the PHP server. It belongs on a developer's machine, used by that developer. Never register it in production, and never serve it to editors of a hosted site: they would all be working under your account.
:::

What to expect, measured on the main sandbox:

| Action | Time |
|---|---|
| Rewrite, shorten or change the tone of a field | about 6 s |
| Describe an image (Claude Code reads the uploaded file) | about 8 s |
| Change one section | about 6–10 s |
| Add a whole page (8 sections) | about 45 s |

## 1. Requirements

- Claude Code installed and logged in (`claude --version` answers, `claude -p "hi"` replies).
- The PHP server runs as **you**, so it sees your login: `symfony serve` or `php -S`, not a PHP-FPM pool running as `www-data`.
- `symfony/process` (`composer require --dev symfony/process` if your app does not already pull it in).

## 2. The provider

`src/Ai/ClaudeCodeAiProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Ai;

use ContentBlocks\Ai\AiAction;
use ContentBlocks\Ai\AiProviderInterface;
use ContentBlocks\Ai\AltTextRequest;
use ContentBlocks\Ai\ContentRequest;
use ContentBlocks\Ai\TextRequest;
use Symfony\Component\Process\Process;

/**
 * Development only: answers the AI actions by running `claude -p` under the
 * developer's own Claude Code login.
 */
final class ClaudeCodeAiProvider implements AiProviderInterface
{
    private const TEXT_SCHEMA = '{"type":"object","properties":{"text":{"type":"string"}},'
        . '"required":["text"],"additionalProperties":false}';

    public function __construct(
        private readonly string $projectDir,
        private readonly string $binary = 'claude',
        private readonly ?string $model = null,
        private readonly int $timeout = 300,
    ) {
    }

    public function supports(string $action): bool
    {
        return \in_array($action, AiAction::PROVIDED, true);
    }

    public function rewriteText(TextRequest $request): string
    {
        $instruction = match ($request->action) {
            AiAction::SHORTEN => 'Shorten this text, keeping its meaning and its key words.',
            AiAction::TONE => sprintf('Rewrite this text in a %s tone, keeping its meaning.', $request->tone ?? 'neutral'),
            default => 'Rewrite this text so it reads better and sells better, keeping its meaning.',
        };

        $rules = [
            // The request locale is the editor's interface, not the content's.
            'Answer in the same language as the input text.',
            $request->html
                ? 'Input and output are HTML: keep simple tags only (p, strong, em, a, ul, ol, li, h2-h4).'
                : 'Plain text only: no markdown, no quotes around it.',
        ];
        if ($request->maxLength !== null) {
            $rules[] = sprintf('At most %d characters.', $request->maxLength);
        }
        if ($request->label !== null) {
            $rules[] = sprintf('It is the "%s" field of a "%s" block on a web page.', $request->label, $request->blockType ?? 'content');
        }
        if ($request->brief !== null) {
            $rules[] = 'About the page: ' . $request->brief;
        }

        $answer = $this->ask(
            'You edit website copy for a page builder. Answer with the new text only, in the `text` field.',
            $instruction . "\n\n" . implode("\n", $rules) . "\n\nText:\n" . $request->text,
            self::TEXT_SCHEMA,
        );

        return \is_string($answer['text'] ?? null) ? $answer['text'] : '';
    }

    public function describeImage(AltTextRequest $request): string
    {
        $file = $this->localFile($request->src);
        $prompt = sprintf(
            "Write the alt text of this image for a web page: one short sentence, no \"image of\". "
            . "Use the language of the text beside it; without any, the language of the locale \"%s\".\n",
            $request->locale,
        );
        $prompt .= $file !== null
            ? sprintf("Read the image file at %s to see it.\n", $file)
            : sprintf("The image is at %s; you cannot open it, so describe it from its name and context.\n", $request->url);
        if ($request->context !== null) {
            $prompt .= 'Text beside the image: ' . $request->context . "\n";
        }
        if ($request->brief !== null) {
            $prompt .= 'About the page: ' . $request->brief . "\n";
        }

        $answer = $this->ask(
            'You write accessible alt text. Answer in the `text` field only.',
            $prompt,
            self::TEXT_SCHEMA,
            $file !== null ? \dirname($file) : null,
        );

        return \is_string($answer['text'] ?? null) ? $answer['text'] : '';
    }

    public function generateContent(ContentRequest $request): array
    {
        $schema = $request->schema;
        $context = [
            'task' => match ($request->action) {
                AiAction::ADD => sprintf(
                    'Write the new content the editor asks for: one or more sections (at most %d), inserted '
                    . 'after the selected section, or at the end of the page. A whole page on an empty page.',
                    $request->maxSections,
                ),
                AiAction::EDIT_SECTION => 'Revise the one section in `current` as the editor asks. Answer with '
                    . 'exactly one section: it replaces the current one, in place.',
                AiAction::EDIT_PAGE => sprintf(
                    'Revise the page in `current` as the editor asks. Answer with the whole page, in order, at '
                    . 'most %d sections: it replaces the current one.',
                    $request->maxSections,
                ),
                default => '',
            },
            'current' => $request->current,
            'locale' => $request->locale,
            'brief' => $request->brief,
            'blocks' => $schema->blocks,
            'layouts' => $schema->layouts,
            'palette' => $schema->palette,
            'sectionStyles' => $schema->sectionStyles,
            'structure' => $schema->structure,
            'examplesOfTheHouseStyle' => $schema->examples,
            'pageSoFar' => $request->existing,
        ];

        $system = <<<'TXT'
            You compose web pages for a block-based page builder. You answer with a JSON document
            matching the given JSON Schema: {"sections": [...]}, each section a "content-blocks/section-v1"
            payload (layout, optional settings, columns with a "col-N" preset each, blocks with a type and data).

            Rules:
            - Use only the block types listed in `blocks`, each for the purpose it states.
            - A section's columns take the presets of its layout, in order, adding up to 12.
            - Follow the brand: colours only from `palette` (by their `color` value, following their role),
              section looks only from `sectionStyles` (by name, through settings.styleName).
            - Contrast: on a background whose palette `tone` is "dark", give every title and text block a
              light palette colour (tone "light"); on a light background, leave text colours empty.
            - Image fields: reuse a path seen in the examples or the page so far, or leave them empty.
              Never invent a path or a URL.
            - Write real, persuasive copy, not placeholders, in the language of `pageSoFar`; on an empty
              page, in the language of the editor's request (`locale` is only the editor's interface).
            - Fit with `pageSoFar`, and learn the house style from `examplesOfTheHouseStyle`.
            - When revising (`current` is not empty), change only what the editor asks: keep every other
              section, block, text, image path, colour and setting exactly as it is.
            - Never answer with an empty `sections` list.
            TXT;

        return $this->ask(
            $system,
            json_encode($context, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)
                . "\n\nThe editor asks:\n" . $request->prompt,
            json_encode($schema->schema, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<mixed>
     */
    private function ask(string $system, string $prompt, string $jsonSchema, ?string $readDir = null): array
    {
        $command = [
            $this->binary, '-p',
            '--output-format', 'json',
            '--no-session-persistence',
            '--strict-mcp-config',
            '--safe-mode',
            '--system-prompt', $system,
            '--json-schema', $jsonSchema,
            '--tools', $readDir !== null ? 'Read' : '',
        ];
        if ($readDir !== null) {
            $command = [...$command, '--allowedTools', 'Read', '--add-dir', $readDir];
        }
        if ($this->model !== null && $this->model !== '') {
            $command = [...$command, '--model', $this->model];
        }

        // Out of the project, so nothing in it is picked up as context.
        $process = new Process($command, $readDir ?? sys_get_temp_dir(), null, $prompt, $this->timeout);
        $process->run();

        // What the model really answered, for when a page comes out odd.
        @file_put_contents($this->projectDir . '/var/ai-last-response.json', $process->getOutput());

        $output = json_decode($process->getOutput(), true);
        if (!$process->isSuccessful() || !\is_array($output) || ($output['is_error'] ?? false) === true) {
            throw new \RuntimeException(sprintf(
                'claude -p failed (exit %s): %s',
                (string) $process->getExitCode(),
                mb_substr($process->getErrorOutput(), 0, 500),
            ));
        }

        if (\is_array($output['structured_output'] ?? null)) {
            return $output['structured_output'];
        }
        // A schema as large as a page's can come back as plain JSON instead.
        $result = \is_string($output['result'] ?? null) ? json_decode($output['result'], true) : null;

        return \is_array($result) ? $result : [];
    }

    /** The uploaded file behind a stored path, when it is on this disk. */
    private function localFile(string $src): ?string
    {
        if (preg_match('#^https?://#i', $src) === 1) {
            return null;
        }
        $public = $this->projectDir . '/public/';
        $path = realpath($public . ltrim((string) parse_url($src, \PHP_URL_PATH), '/'));

        return $path !== false && str_starts_with($path, $public) && is_file($path) ? $path : null;
    }
}
```

What each call is allowed to do:

- **No tools**, except `Read` when an image has to be seen, and then only inside that image's directory (`--add-dir`).
- **No MCP servers** (`--strict-mcp-config`).
- **No session saved** (`--no-session-persistence`).
- **No `CLAUDE.md`, hook or plugin** from your project (`--safe-mode`, and a working directory outside the project).
- **A JSON Schema** constrains the answer (`--json-schema`). For a whole page the schema is large, and the CLI may answer in plain JSON instead; the provider reads that too. Either way, the package replays every block through its form before anything reaches the draft, so a malformed answer costs a field, not the page.

## 3. Register it in `dev` only

Keep the class out of the general `App\` import, and declare it under `when@dev`:

```yaml
# config/services.yaml
services:
    App\:
        resource: '../src/'
        exclude:
            - '../src/Kernel.php'
            - '../src/Ai/ClaudeCodeAiProvider.php'

when@dev:
    services:
        App\Ai\ClaudeCodeAiProvider:
            autoconfigure: true    # tags it as the AI provider
            arguments:
                $projectDir: '%kernel.project_dir%'
                $model: '%env(default::CLAUDE_MODEL)%'   # empty: Claude Code's default model
```

In `prod` the class is never loaded: the builder shows no AI and the endpoints answer `404`, unless you register a real provider there.

If your application also has a real provider, the first registered one answers. Exclude the real one in `dev`, or give this one a higher tag priority.

## 4. Use it

Start the server as yourself and open the builder:

```bash
symfony serve   # or: php -S 127.0.0.1:8000 -t public
```

- **Generate** → *Add content* on an empty page writes a first draft of the whole page from your prompt. With a [brief provider](../ai.md#a-brief-from-the-host), the product sheet goes along.
- ✦ on a section's toolbar → *Change the selected section* reworks one section in place.
- ✦ beside a field rewrites or shortens it, or describes the image beside it. Only an image stored under your `public/` directory (an upload) is actually seen; for a remote URL (a CDN, a placeholder service) the model can only go by the file name and the text around it.

Everything lands in the draft. Read it, fix it, then Publish. `Ctrl/Cmd-Z` undoes a generation in one step.

When a page comes out wrong, `var/ai-last-response.json` holds the model's raw answer.

## Without a provider at all

For a batch of pages, you can also skip the builder: ask Claude Code itself, in your terminal, to read `bin/console content-blocks:schema --area=42` and write the page, then load its answer with `bin/console content-blocks:ai:apply 42 page.json`. That is the same way back in, through the same replay. See [From your own pipeline](../ai.md#from-your-own-pipeline).
