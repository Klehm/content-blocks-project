<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cb_block')]
class Block
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Column::class, inversedBy: 'blocks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Column $column = null;

    /**
     * The column this block is published in, when a draft move took it
     * somewhere else — null the rest of the time, which is nearly always.
     *
     * Dragging a block into another column writes {@see $column} straight
     * away, because that FK is what the builder, the preview and Doctrine's
     * cascades all navigate. That makes it the *draft* location, and leaves
     * the published render with no way to keep the block where it was — hence
     * this. A plain id, deliberately: an association would give a removed
     * column a live reference to fight over at publish time, and nothing here
     * needs to navigate it — {@see \ContentBlocks\Rendering\BlockRenderer}
     * buckets by id, and the publisher resolves it against the area it is
     * already walking.
     */
    #[ORM\Column(name: 'published_column_id', nullable: true)]
    private ?int $publishedColumnId = null;

    #[ORM\Column(length: 80)]
    private string $type = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'published_data', type: 'json', nullable: true)]
    private ?array $publishedData = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'draft_data', type: 'json', nullable: true)]
    private ?array $draftData = null;

    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    #[ORM\Column(name: 'preview_position', type: 'smallint')]
    private int $previewPosition = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColumn(): ?Column
    {
        return $this->column;
    }

    public function setColumn(?Column $column): self
    {
        $this->column = $column;

        return $this;
    }

    public function getPublishedColumnId(): ?int
    {
        return $this->publishedColumnId;
    }

    public function setPublishedColumnId(?int $columnId): self
    {
        $this->publishedColumnId = $columnId;

        return $this;
    }

    /**
     * Draft move into another column: records where the block is published on
     * the way out, so the public page can keep showing it there until Publish.
     * Nothing to record for a block that was never published — the public page
     * doesn't show it at all — nor for a move that lands it back home.
     */
    public function moveTo(Column $target): self
    {
        if ($this->publishedData !== null) {
            $home = $this->publishedColumnId ?? $this->column?->getId();
            $this->publishedColumnId = $home === $target->getId() ? null : $home;
        }

        return $this->attachTo($target);
    }

    /**
     * Undo of {@see moveTo()} — puts the block back in the column it is
     * published in and forgets the move. Called by the publisher on discard,
     * which is the only place that can resolve the id back to a Column.
     */
    public function restoreTo(Column $publishedColumn): self
    {
        $this->publishedColumnId = null;

        return $this->attachTo($publishedColumn);
    }

    /**
     * Moves the block between two columns on *both* sides of the association.
     *
     * Writing the FK alone would leave the old column's collection holding a
     * block that is no longer its own — which matters, because Doctrine
     * cascades a Column/Section removal through that collection and would take
     * the block down with it. Doing it in this order is also what keeps the
     * block alive: `removeElement` schedules an orphan removal, and the `add`
     * on the new collection cancels it.
     */
    private function attachTo(Column $target): self
    {
        if ($this->column === $target) {
            return $this;
        }

        $this->column?->getBlocks()->removeElement($this);
        $this->column = $target;
        $target->getBlocks()->add($this);

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getPublishedData(): ?array
    {
        return $this->publishedData;
    }

    /** @param array<string, mixed>|null $data */
    public function setPublishedData(?array $data): self
    {
        $this->publishedData = $data;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getDraftData(): ?array
    {
        return $this->draftData;
    }

    /** @param array<string, mixed>|null $data */
    public function setDraftData(?array $data): self
    {
        $this->draftData = $data;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getPreviewPosition(): int
    {
        return $this->previewPosition;
    }

    public function setPreviewPosition(int $previewPosition): self
    {
        $this->previewPosition = $previewPosition;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;

        return $this;
    }

    /**
     * Promote draft state to published. Caller is responsible for handling
     * deleted blocks separately (em->remove instead of publish).
     */
    public function publish(): void
    {
        if ($this->draftData !== null) {
            $this->publishedData = $this->draftData;
            $this->draftData = null;
        }
        $this->position = $this->previewPosition;
        // The column FK already points at the draft location; publishing it
        // is just forgetting where the block used to live.
        $this->publishedColumnId = null;
    }

    /**
     * Revert draft state to match the published one.
     *
     * A draft move is *not* undone here: putting the block back means writing
     * the column FK, and only the caller knows the Column object behind
     * {@see $publishedColumnId}. {@see \ContentBlocks\Publishing\ContentAreaPublisher::discardDraft()}
     * does it, walking the area it already holds.
     */
    public function revertDraft(): void
    {
        $this->draftData = null;
        $this->previewPosition = $this->position;
        $this->deleted = false;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draftData !== null
            || $this->previewPosition !== $this->position
            || $this->publishedColumnId !== null
            || $this->deleted
            || $this->publishedData === null;
    }
}
