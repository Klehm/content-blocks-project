<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

/**
 * Indexes the available {@see RichTextEditorInterface} implementations by
 * name, so the `rich_text` block can resolve `options.editor` to an adapter.
 *
 * Wired with a `tagged_iterator`, like the core's palette and section-style
 * registries: a host-registered editor is auto-tagged and shows up here with
 * no further wiring. A later service wins a name collision, which is what
 * lets a host replace a shipped editor's adapter without renaming it.
 */
final class RichTextEditorRegistry
{
    /** @var array<string, RichTextEditorInterface>|null */
    private ?array $byName = null;

    /**
     * @param iterable<RichTextEditorInterface> $editors
     */
    public function __construct(
        private readonly iterable $editors = [],
    ) {
    }

    /**
     * @throws \InvalidArgumentException when no editor answers to that name —
     *                                   a config typo, or an adapter the host meant to register and did not
     */
    public function get(string $name): RichTextEditorInterface
    {
        $editors = $this->all();

        if (!isset($editors[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown rich-text editor "%s". Available: %s. Add one by registering a service implementing %s.',
                $name,
                $editors === [] ? '(none)' : implode(', ', array_keys($editors)),
                RichTextEditorInterface::class,
            ));
        }

        return $editors[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    /**
     * @return array<string, RichTextEditorInterface>
     */
    public function all(): array
    {
        if ($this->byName === null) {
            $this->byName = [];
            foreach ($this->editors as $editor) {
                $this->byName[$editor::getName()] = $editor;
            }
        }

        return $this->byName;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }
}
