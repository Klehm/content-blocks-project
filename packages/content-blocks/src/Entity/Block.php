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
    }

    /**
     * Revert draft state to match the published one.
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
            || $this->deleted
            || $this->publishedData === null;
    }
}
