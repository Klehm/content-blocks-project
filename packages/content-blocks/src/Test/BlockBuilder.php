<?php

declare(strict_types=1);

namespace ContentBlocks\Test;

use ContentBlocks\Entity\Block;

/**
 * Configures one block of a {@see ContentAreaBuilder} tree.
 *
 * @experimental Same status as {@see ContentAreaBuilder}.
 */
final class BlockBuilder
{
    use SetsEntityId;

    private ?int $id = null;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @var array<string, mixed>|null */
    private ?array $draftData = null;

    /** @var array<string, mixed>|null */
    private ?array $publishedData = null;

    /** Set once either side is named explicitly, which turns off the move. */
    private bool $sidesAreExplicit = false;

    private ?bool $published = null;

    private bool $deleted = false;

    private ?int $position = null;

    private ?int $previewPosition = null;

    /**
     * @internal Built by {@see ColumnBuilder::block()}.
     */
    public function __construct(private readonly string $type)
    {
    }

    public function withId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * The block payload, landing on whichever side this block resolves to.
     *
     * @param array<string, mixed> $data
     */
    public function data(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Pins the draft payload regardless of the resolved state.
     *
     * Setting this alongside {@see self::publishedData()} is how you model a
     * block edited since its last publish — the two sides differ, and the
     * public page still shows the old one.
     *
     * @param array<string, mixed>|null $data
     */
    public function draftData(?array $data): self
    {
        $this->draftData = $data;
        $this->sidesAreExplicit = true;

        return $this;
    }

    /**
     * Pins the published payload regardless of the resolved state.
     *
     * @param array<string, mixed>|null $data
     */
    public function publishedData(?array $data): self
    {
        $this->publishedData = $data;
        $this->sidesAreExplicit = true;

        return $this;
    }

    /** Leaves this block as a never-published draft. */
    public function draft(): self
    {
        $this->published = false;

        return $this;
    }

    /** Publishes this block even inside an otherwise-draft column. */
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

    /** Soft-deletes the block: still stored, dropped at publish time. */
    public function deleted(): self
    {
        $this->deleted = true;

        return $this;
    }

    /**
     * @internal Called by {@see ColumnBuilder::build()}.
     */
    public function build(bool $inheritedPublished, int $index): Block
    {
        $published = $this->published ?? $inheritedPublished;

        $block = new Block();
        if ($this->id !== null) {
            $this->setEntityId($block, $this->id);
        }
        $block->setType($this->type);
        $block->setPreviewPosition($index);

        if ($this->sidesAreExplicit) {
            // The caller has said what each side holds; promoting the draft on
            // top of that would overwrite the published payload they just set.
            $block->setDraftData($this->draftData);
            $block->setPublishedData($this->publishedData);
            if ($published) {
                $block->setPosition($block->getPreviewPosition());
            }
        } else {
            $block->setDraftData($this->data);
            if ($published) {
                $block->publish();
            }
        }

        if ($this->position !== null) {
            $block->setPosition($this->position);
            $block->setPreviewPosition((int) $this->previewPosition);
        }
        if ($this->deleted) {
            $block->setDeleted(true);
        }

        return $block;
    }
}
