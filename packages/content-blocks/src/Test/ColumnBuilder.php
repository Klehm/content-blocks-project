<?php

declare(strict_types=1);

namespace ContentBlocks\Test;

use ContentBlocks\Entity\Column;

/**
 * Configures one column of a {@see ContentAreaBuilder} tree.
 *
 * @experimental Same status as {@see ContentAreaBuilder}.
 */
final class ColumnBuilder
{
    use SetsEntityId;

    private ?int $id = null;

    private ?string $preset = null;

    private ?bool $published = null;

    private bool $deleted = false;

    private ?int $position = null;

    private ?int $previewPosition = null;

    /** @var list<BlockBuilder> */
    private array $blocks = [];

    /**
     * @internal Built by {@see SectionBuilder::column()}.
     */
    public function __construct()
    {
    }

    public function withId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /** A grid preset, e.g. `col-12`, `col-6`. */
    public function preset(string $preset): self
    {
        $this->preset = $preset;

        return $this;
    }

    /** Leaves this column — and its blocks — as a never-published draft. */
    public function draft(): self
    {
        $this->published = false;

        return $this;
    }

    /** Publishes this column even inside an otherwise-draft section. */
    public function published(): self
    {
        $this->published = true;

        return $this;
    }

    /** @see SectionBuilder::position() */
    public function position(int $position, ?int $previewPosition = null): self
    {
        $this->position = $position;
        $this->previewPosition = $previewPosition ?? $position;

        return $this;
    }

    /** Soft-deletes the column: still stored, dropped at publish time. */
    public function deleted(): self
    {
        $this->deleted = true;

        return $this;
    }

    /**
     * Adds a block.
     *
     * `$data` lands on the published or the draft side according to the state
     * this column resolves to — which is the whole point of the default. A
     * block that needs both sides at once (an edited-but-unpublished block)
     * sets them explicitly through the `$configure` callback.
     *
     * @param array<string, mixed>|null            $data
     * @param (callable(BlockBuilder): mixed)|null $configure
     */
    public function block(string $type, ?array $data = null, ?callable $configure = null): self
    {
        $block = new BlockBuilder($type);
        if ($data !== null) {
            $block->data($data);
        }
        if ($configure !== null) {
            $configure($block);
        }
        $this->blocks[] = $block;

        return $this;
    }

    /**
     * @internal Called by {@see SectionBuilder::build()}.
     */
    public function build(bool $inheritedPublished, int $index): Column
    {
        $published = $this->published ?? $inheritedPublished;

        $column = new Column();
        if ($this->id !== null) {
            $this->setEntityId($column, $this->id);
        }
        if ($this->preset !== null) {
            $column->setPreset($this->preset);
        }
        $column->setPreviewPosition($index);

        foreach ($this->blocks as $i => $block) {
            $column->addBlock($block->build($published, $i));
        }

        if ($published) {
            $column->publish();
        }

        if ($this->position !== null) {
            $column->setPosition($this->position);
            $column->setPreviewPosition((int) $this->previewPosition);
        }
        if ($this->deleted) {
            $column->setDeleted(true);
        }

        return $column;
    }
}
