<?php

declare(strict_types=1);

namespace App\Entity;

use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\Mapping as ORM;

/**
 * The host-owned entity the builder hangs off — the integration pattern from
 * the docs, reduced to what the Encore tests need.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_page')]
#[ORM\HasLifecycleCallbacks]
class Page
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ContentArea $contentArea = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
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

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->contentArea === null) {
            $this->contentArea = new ContentArea();
        }
    }
}
