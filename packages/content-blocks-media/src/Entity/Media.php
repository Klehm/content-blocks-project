<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One file of the library: its stored path plus what a path cannot say (title,
 * alt, dimensions). Inserted through MediaRows, not the ORM.
 *
 * @see \ContentBlocks\Media\Storage\MediaRows
 * @see docs/internals/media.md#a-row-per-file-the-file-stays-a-path
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_media')]
#[ORM\UniqueConstraint(name: 'cb_media_path', columns: ['path'])]
#[ORM\Index(name: 'cb_media_kind', columns: ['kind'])]
#[ORM\Index(name: 'cb_media_created_at', columns: ['created_at'])]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MediaFolder::class)]
    #[ORM\JoinColumn(name: 'folder_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?MediaFolder $folder = null;

    #[ORM\Column(name: 'mime_type', type: Types::STRING, length: 127, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $size = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $alt = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        /** Public path, in the exact spelling stored inside block data. */
        #[ORM\Column(type: Types::STRING, length: 512)]
        private string $path,
        #[ORM\Column(type: Types::STRING, length: 16)]
        private string $kind,
        #[ORM\Column(type: Types::STRING, length: 255)]
        private string $title,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function setAlt(string $alt): void
    {
        $this->alt = $alt;
        $this->touch();
    }

    public function getFolder(): ?MediaFolder
    {
        return $this->folder;
    }

    public function moveTo(?MediaFolder $folder): void
    {
        $this->folder = $folder;
        $this->touch();
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
