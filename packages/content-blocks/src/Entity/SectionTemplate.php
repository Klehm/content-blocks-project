<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A reusable, named snapshot of a single Section (layout + settings +
 * columns + blocks + their data), stored in a global library so editors can
 * re-insert it into any ContentArea.
 *
 * This is a *snapshot*, not a live template: inserting clones the payload:
 * later edits to the inserted section never propagate back, and editing the
 * template never touches areas already built from it.
 *
 * Asset references inside `payload` are kept as plain storage paths (not
 * embedded binaries) — the library lives inside a single app, so duplicating
 * files would be wasteful. The trade-off: deleting the original upload breaks
 * templates that reference it.
 *
 * `blockTypes` caches the set of block-type identifiers used by the payload
 * so the library can flag a template as incompatible (a block type is no
 * longer registered) without deserializing the whole payload.
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
