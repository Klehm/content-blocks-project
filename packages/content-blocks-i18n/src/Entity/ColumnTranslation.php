<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Entity;

use ContentBlocks\Entity\Column;
use ContentBlocks\I18n\Repository\ColumnTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One column's settings in one target locale — its tab title. Same payload
 * and draft/published rules as {@see BlockTranslation}, beside a column.
 *
 * @see docs/internals/i18n.md#tab-titles-are-translated-beside-the-column
 */
#[ORM\Entity(repositoryClass: ColumnTranslationRepository::class)]
#[ORM\Table(name: 'cb_column_translation')]
#[ORM\UniqueConstraint(name: 'cb_column_translation_unique', columns: ['column_id', 'locale'])]
#[ORM\Index(name: 'cb_column_translation_locale', columns: ['locale'])]
class ColumnTranslation
{
    use TranslationPayload;

    /** The only path a column carries today. */
    public const LABEL = 'label';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Column::class)]
    #[ORM\JoinColumn(name: 'column_id', nullable: false, onDelete: 'CASCADE')]
    private ?Column $column = null;

    #[ORM\Column(length: 16)]
    private string $locale = '';

    public function __construct(?Column $column = null, string $locale = '')
    {
        $this->column = $column;
        $this->locale = $locale;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getColumn(): ?Column
    {
        return $this->column;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
