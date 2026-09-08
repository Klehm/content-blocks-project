<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A named **snapshot** of one Section, not a live template: inserting clones
 * the payload, and neither side propagates to the other afterwards.
 *
 * @see docs/internals/section-templates.md#what-a-snapshot-holds
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_section_template')]
class SectionTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /** @var list<string> */
    #[ORM\Column(name: 'block_types', type: 'json')]
    private array $blockTypes = [];

    /**
     * Unlike a ContentArea's, this stamp never moves: a snapshot is frozen, so
     * it really does describe its payload. `null` means unknown, not 0.
     *
     * @see docs/internals/versioning.md#the-content-version
     */
    #[ORM\Column(name: 'content_version', type: 'integer', nullable: true)]
    private ?int $contentVersion = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /** @return list<string> */
    public function getBlockTypes(): array
    {
        return $this->blockTypes;
    }

    /** @param list<string> $blockTypes */
    public function setBlockTypes(array $blockTypes): self
    {
        $this->blockTypes = array_values($blockTypes);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
