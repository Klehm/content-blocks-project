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

    /**
     * Schema generation this area's content was last **written** under — the
     * host-owned `content_blocks.content_version`, stamped by the same onFlush
     * listener as `updatedAt`.
     *
     * Read it as "last written under version N", not "conforms to version N".
     * Editing one block re-stamps the whole area while its other blocks keep
     * whatever shape they had, so the value is a targeting index for migrations
     * (`WHERE content_version < N` finds what certainly predates a change), not
     * a guarantee. Migrate before letting editors work on a new version.
     *
     * `null` means the row predates versioning — treat it as "unknown", never
     * as 0.
     */
    #[ORM\Column(name: 'content_version', type: 'integer', nullable: true)]
    private ?int $contentVersion = null;

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

    public function getContentVersion(): ?int
    {
        return $this->contentVersion;
    }

    public function setContentVersion(?int $contentVersion): self
    {
        $this->contentVersion = $contentVersion;

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
