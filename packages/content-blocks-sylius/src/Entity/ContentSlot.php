<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Entity;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Sylius\Slot\SlotType;
use Doctrine\ORM\Mapping as ORM;

/**
 * Builder content attached to a native shop page — a channel's homepage, a
 * product, a taxon — keyed by the subject's code, so no Sylius model changes.
 *
 * @see docs/internals/sylius.md#native-pages
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_sylius_slot')]
#[ORM\UniqueConstraint(name: 'cb_sylius_slot_subject', columns: ['type', 'subject'])]
class ContentSlot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: ContentArea::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(name: 'content_area_id', nullable: false, onDelete: 'CASCADE')]
    private ContentArea $contentArea;

    public function __construct(
        #[ORM\Column(length: 32)]
        private string $type,
        #[ORM\Column(length: 191)]
        private string $subject,
    ) {
        if (!\in_array($type, SlotType::ALL, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown slot type "%s".', $type));
        }
        $this->contentArea = new ContentArea();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** One of {@see SlotType::ALL}. */
    public function getType(): string
    {
        return $this->type;
    }

    /** The channel code of a homepage, the product or taxon code otherwise. */
    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContentArea(): ContentArea
    {
        return $this->contentArea;
    }
}
