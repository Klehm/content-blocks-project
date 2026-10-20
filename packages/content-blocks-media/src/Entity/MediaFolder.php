<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A folder of the library, nested through `parent`. A deleted folder takes its
 * subfolders with it; its files move to the root (`folder_id` SET NULL).
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_media_folder')]
#[ORM\Index(name: 'cb_media_folder_position', columns: ['parent_id', 'position'])]
class MediaFolder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: self::class)]
        #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
        private ?MediaFolder $parent = null,
    ) {
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

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function getParent(): ?MediaFolder
    {
        return $this->parent;
    }

    public function moveTo(?MediaFolder $parent, int $position): void
    {
        $this->parent = $parent;
        $this->position = $position;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
