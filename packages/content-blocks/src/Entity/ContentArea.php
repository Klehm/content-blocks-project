<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'cb_content_area')]
class ContentArea
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Touched by the Doctrine onFlush listener whenever any Section / Column /
     * Block in this area is created, updated, or removed. Nullable for
     * back-compat with rows created before the column was added; new rows get
     * a non-null value the first time the listener runs.
     */
    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, Section> */
    #[ORM\OneToMany(mappedBy: 'contentArea', targetEntity: Section::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $sections;

    public function __construct()
    {
        $this->sections = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /** @return Collection<int, Section> */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(Section $section): self
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setContentArea($this);
        }

        return $this;
    }

    public function removeSection(Section $section): self
    {
        if ($this->sections->removeElement($section)) {
            if ($section->getContentArea() === $this) {
                $section->setContentArea(null);
            }
        }

        return $this;
    }

    public function hasUnpublishedChanges(): bool
    {
        foreach ($this->sections as $section) {
            if ($section->hasUnpublishedChanges()) {
                return true;
            }
            foreach ($section->getColumns() as $column) {
                if ($column->hasUnpublishedChanges()) {
                    return true;
                }
                foreach ($column->getBlocks() as $block) {
                    if ($block->hasUnpublishedChanges()) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
