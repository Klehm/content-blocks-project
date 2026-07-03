<?php

declare(strict_types=1);

namespace App\Entity;

use App\Form\Type\ModelType;
use ContentBlocks\Entity\ContentArea;
use Doctrine\ORM\Mapping as ORM;
use Sylius\Resource\Metadata\AsResource;
use Sylius\Resource\Metadata\BulkDelete;
use Sylius\Resource\Metadata\Create;
use Sylius\Resource\Metadata\Delete;
use Sylius\Resource\Metadata\Index;
use Sylius\Resource\Metadata\Update;
use Sylius\Resource\Model\ResourceInterface;

/**
 * A reusable page-layout model: a near-copy of Page holding its own
 * ContentArea, but with no public slug/route — it exists to be built once and
 * then reused on real pages via the builder's "Insert content" picker.
 *
 * Created by the "Save page as model" builder action (PageController).
 */
#[AsResource(
    section: 'admin',
    formType: ModelType::class,
    templatesDir: '@SyliusAdminUi/crud',
    routePrefix: '/admin',
    operations: [
        new Index(grid: 'app_model'),
        new Create(),
        new Update(),
        new Delete(),
        new BulkDelete(),
    ],
)]
#[ORM\Entity]
#[ORM\Table(name: 'app_model')]
#[ORM\HasLifecycleCallbacks]
class Model implements ResourceInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ContentArea $contentArea = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getContentArea(): ?ContentArea
    {
        return $this->contentArea;
    }

    public function setContentArea(?ContentArea $contentArea): static
    {
        $this->contentArea = $contentArea;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();

        if ($this->contentArea === null) {
            $this->contentArea = new ContentArea();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
