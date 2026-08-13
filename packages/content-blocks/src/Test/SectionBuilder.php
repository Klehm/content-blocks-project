<?php

declare(strict_types=1);

namespace ContentBlocks\Test;

use ContentBlocks\Entity\Section;

/**
 * Configures one section of a {@see ContentAreaBuilder} tree.
 *
 * @experimental Same status as {@see ContentAreaBuilder}.
 */
final class SectionBuilder
{
    use SetsEntityId;

    private ?int $id = null;

    private ?string $layout = null;

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    private ?bool $published = null;

    private bool $deleted = false;

    private ?int $position = null;

    private ?int $previewPosition = null;

    /** @var list<ColumnBuilder> */
    private array $columns = [];

    /**
     * @internal Built by {@see ContentAreaBuilder::section()}.
     */
    public function __construct()
    {
    }

    public function withId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    /** One of the `Section::LAYOUT_*` constants. */
    public function layout(string $layout): self
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * The section's styling / layout settings, in the shape of
     * `Section::$draftSettings`.
     *
     * @param array<string, mixed> $settings
     */
    public function settings(array $settings): self
    {
        $this->settings = $settings;

        return $this;
    }

    /** Leaves this section — and its subtree — as a never-published draft. */
    public function draft(): self
    {
        $this->published = false;

        return $this;
    }

    /** Publishes this section even inside an otherwise-draft area. */
    public function published(): self
    {
        $this->published = true;

        return $this;
    }

    /**
     * Overrides the auto-incremented ordering.
     *
     * Passing a `$previewPosition` that differs from `$position` is what a
     * pending reorder looks like — the case `hasUnpublishedChanges()` reports.
     */
    public function position(int $position, ?int $previewPosition = null): self
    {
        $this->position = $position;
        $this->previewPosition = $previewPosition ?? $position;

        return $this;
    }

    /** Soft-deletes the section: still stored, dropped at publish time. */
    public function deleted(): self
    {
        $this->deleted = true;

        return $this;
    }

    /**
     * @param (callable(ColumnBuilder): mixed)|null $configure
     */
    public function column(?callable $configure = null): self
    {
        $column = new ColumnBuilder();
        if ($configure !== null) {
            $configure($column);
        }
        $this->columns[] = $column;

        return $this;
    }

    /**
     * @internal Called by {@see ContentAreaBuilder::build()}.
     *
     * The published state is resolved here rather than at construction so the
     * order of calls inside the closure cannot matter: `->column(…)->draft()`
     * and `->draft()->column(…)` build the same tree.
     */
    public function build(bool $inheritedPublished, int $index): Section
    {
        $published = $this->published ?? $inheritedPublished;

        $section = new Section();
        if ($this->id !== null) {
            $this->setEntityId($section, $this->id);
        }
        if ($this->layout !== null) {
            $section->setLayout($this->layout);
        }
        $section->setPreviewPosition($index);
        if ($this->settings !== null) {
            $section->setDraftSettings($this->settings);
        }

        foreach ($this->columns as $i => $column) {
            $section->addColumn($column->build($published, $i));
        }

        // The entity's own publish() decides what "published" means (settings
        // promoted, position synced, publishedAt stamped) — restating it here
        // would be a second definition free to drift from the first.
        if ($published) {
            $section->publish();
        }

        // Applied last: publish() syncs position from previewPosition, and an
        // explicitly requested ordering has to survive that.
        if ($this->position !== null) {
            $section->setPosition($this->position);
            $section->setPreviewPosition((int) $this->previewPosition);
        }
        if ($this->deleted) {
            $section->setDeleted(true);
        }

        return $section;
    }
}
