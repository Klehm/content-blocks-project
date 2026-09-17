<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Entity;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One block's values in one target locale: a flat path → value map, with a
 * digest of the source beside each, and draft/published mirroring {@see Block}.
 *
 * @see docs/internals/i18n.md#why-a-side-table-not-an-envelope-in-blockdata
 */
#[ORM\Entity(repositoryClass: BlockTranslationRepository::class)]
#[ORM\Table(name: 'cb_block_translation')]
#[ORM\UniqueConstraint(name: 'cb_block_translation_unique', columns: ['block_id', 'locale'])]
#[ORM\Index(name: 'cb_block_translation_locale', columns: ['locale'])]
class BlockTranslation
{
    use TranslationPayload;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * CASCADE rather than a listener: the database is the only place that can
     * guarantee it without every delete path opting in.
     */
    #[ORM\ManyToOne(targetEntity: Block::class)]
    #[ORM\JoinColumn(name: 'block_id', nullable: false, onDelete: 'CASCADE')]
    private ?Block $block = null;

    /** BCP 47 as the host spells it — `fr`, `pt_BR`. Stored verbatim. */
    #[ORM\Column(length: 16)]
    private string $locale = '';

    public function __construct(?Block $block = null, string $locale = '')
    {
        $this->block = $block;
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlock(): ?Block
    {
        return $this->block;
    }

    public function setBlock(?Block $block): self
    {
        $this->block = $block;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }
}
