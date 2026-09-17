<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cb_column')]
class Column
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Section::class, inversedBy: 'columns')]
    #[ORM\JoinColumn(name: 'section_id', nullable: false, onDelete: 'CASCADE')]
    private ?Section $section = null;

    /** Draft width preset: a span on a 12-unit grid, "col-12" to "col-1". */
    #[ORM\Column(length: 30)]
    private string $preset = 'col-12';

    /** What the public page renders; null until the column is published. */
    #[ORM\Column(name: 'published_preset', length: 30, nullable: true)]
    private ?string $publishedPreset = null;

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

    /** @var Collection<int, Block> */
    #[ORM\OneToMany(mappedBy: 'column', targetEntity: Block::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $blocks;

    public function __construct()
    {
        $this->blocks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): ?Section
    {
        return $this->section;
    }

    public function setSection(?Section $section): self
    {
        $this->section = $section;

        return $this;
    }

    public function getPreset(): string
    {
        return $this->preset;
    }

    public function setPreset(string $preset): self
    {
        // A column published before the twin existed: pin what it shows.
        if ($this->publishedAt !== null && $this->publishedPreset === null) {
            $this->publishedPreset = $this->preset;
        }
        $this->preset = $preset;

        return $this;
    }

    public function getPublishedPreset(): ?string
    {
        return $this->publishedPreset;
    }

    public function setPublishedPreset(?string $preset): self
    {
        $this->publishedPreset = $preset;

        return $this;
    }

    /**
     * The preset a render uses. A column published before the twin existed
     * has no published preset yet, and its draft one is what it showed.
     */
    public function getEffectivePreset(bool $preferDraft = false): string
    {
        return $preferDraft ? $this->preset : ($this->publishedPreset ?? $this->preset);
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

    /** @return Collection<int, Block> */
    public function getBlocks(): Collection
    {
        return $this->blocks;
    }

    public function addBlock(Block $block): self
    {
        if (!$this->blocks->contains($block)) {
            $this->blocks->add($block);
            $block->setColumn($this);
        }

        return $this;
    }

    public function removeBlock(Block $block): self
    {
        if ($this->blocks->removeElement($block)) {
            if ($block->getColumn() === $this) {
                $block->setColumn(null);
            }
        }

        return $this;
    }

    /**
     * Promote draft position, preset and settings to published. A deleted
     * column is the caller's problem — `em->remove()` rather than this.
     */
    public function publish(): void
    {
        $this->position = $this->previewPosition;
        $this->publishedPreset = $this->preset;
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
        if ($this->publishedPreset !== null) {
            $this->preset = $this->publishedPreset;
        }
        $this->draftSettings = null;
        $this->deleted = false;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->previewPosition !== $this->position
            || ($this->publishedPreset !== null && $this->publishedPreset !== $this->preset)
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
     * Draft settings when asked for and present, else the published ones.
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
