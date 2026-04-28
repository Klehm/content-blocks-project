<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cb_section')]
class Section
{
    public const LAYOUT_FULL = 'full';
    public const LAYOUT_TWO_COLS = 'two_cols';
    public const LAYOUT_THREE_COLS = 'three_cols';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentArea::class, inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContentArea $contentArea = null;

    #[ORM\Column(length: 30)]
    private string $layout = self::LAYOUT_FULL;

    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    #[ORM\Column(name: 'preview_position', type: 'smallint')]
    private int $previewPosition = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'published_settings', type: 'json', nullable: true)]
    private ?array $publishedSettings = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'draft_settings', type: 'json', nullable: true)]
    private ?array $draftSettings = null;

    /** @var Collection<int, Column> */
    #[ORM\OneToMany(mappedBy: 'section', targetEntity: Column::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $columns;

    public function __construct()
    {
        $this->columns = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentArea(): ?ContentArea
    {
        return $this->contentArea;
    }

    public function setContentArea(?ContentArea $contentArea): self
    {
        $this->contentArea = $contentArea;

        return $this;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function setLayout(string $layout): self
    {
        $this->layout = $layout;

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

    /** @return Collection<int, Column> */
    public function getColumns(): Collection
    {
        return $this->columns;
    }

    public function addColumn(Column $column): self
    {
        if (!$this->columns->contains($column)) {
            $this->columns->add($column);
            $column->setSection($this);
        }

        return $this;
    }

    public function removeColumn(Column $column): self
    {
        if ($this->columns->removeElement($column)) {
            if ($column->getSection() === $this) {
                $column->setSection(null);
            }
        }

        return $this;
    }

    /**
     * Promote draft layout state (position + settings) to published. Caller
     * is responsible for handling deleted sections separately (em->remove
     * instead of publish).
     */
    public function publish(): void
    {
        $this->position = $this->previewPosition;
        if ($this->draftSettings !== null) {
            $this->publishedSettings = $this->draftSettings;
            $this->draftSettings = null;
        }
        if ($this->publishedAt === null) {
            $this->publishedAt = new \DateTimeImmutable();
        }
    }

    /**
     * Revert draft state to match the published one.
     */
    public function revertDraft(): void
    {
        $this->previewPosition = $this->position;
        $this->draftSettings = null;
        $this->deleted = false;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->previewPosition !== $this->position
            || $this->draftSettings !== null
            || $this->deleted
            || $this->publishedAt === null;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    /** @return array<string, mixed>|null */
    public function getPublishedSettings(): ?array
    {
        return $this->publishedSettings;
    }

    /** @param array<string, mixed>|null $settings */
    public function setPublishedSettings(?array $settings): self
    {
        $this->publishedSettings = $settings;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getDraftSettings(): ?array
    {
        return $this->draftSettings;
    }

    /** @param array<string, mixed>|null $settings */
    public function setDraftSettings(?array $settings): self
    {
        $this->draftSettings = $settings;

        return $this;
    }

    /**
     * Settings to apply when rendering: drafts override published if set,
     * mirroring the convention used for Block::getDraftData() ?? Block::getPublishedData().
     *
     * @return array<string, mixed>
     */
    public function getEffectiveSettings(bool $preferDraft = false): array
    {
        if ($preferDraft && $this->draftSettings !== null) {
            return $this->draftSettings;
        }

        return $this->publishedSettings ?? [];
    }
}
