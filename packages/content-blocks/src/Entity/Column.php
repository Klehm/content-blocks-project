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
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Section $section = null;

    /** Width preset: "col-12", "col-6", "col-4", etc. */
    #[ORM\Column(length: 30)]
    private string $preset = 'col-12';

    #[ORM\Column(type: 'smallint')]
    private int $position = 0;

    #[ORM\Column(name: 'preview_position', type: 'smallint')]
    private int $previewPosition = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    #[ORM\Column(name: 'published_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

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
        $this->preset = $preset;

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
     * Promote draft layout state (position) to published. Caller is
     * responsible for handling deleted columns separately (em->remove
     * instead of publish).
     */
    public function publish(): void
    {
        $this->position = $this->previewPosition;
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
        $this->deleted = false;
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->previewPosition !== $this->position
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
}
