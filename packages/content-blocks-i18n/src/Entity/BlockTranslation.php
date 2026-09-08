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
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * CASCADE rather than a listener: the database is the only place that can
     * guarantee it without every delete path opting in.
     */
    #[ORM\ManyToOne(targetEntity: Block::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Block $block = null;

    /** BCP 47 as the host spells it — `fr`, `pt_BR`. Stored verbatim. */
    #[ORM\Column(length: 16)]
    private string $locale = '';

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'draft_values', type: 'json', nullable: true)]
    private ?array $draftValues = null;

    /** @var array<string, string>|null */
    #[ORM\Column(name: 'draft_digests', type: 'json', nullable: true)]
    private ?array $draftDigests = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'published_values', type: 'json', nullable: true)]
    private ?array $publishedValues = null;

    /** @var array<string, string>|null */
    #[ORM\Column(name: 'published_digests', type: 'json', nullable: true)]
    private ?array $publishedDigests = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

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

    /** @return array<string, mixed>|null */
    public function getDraftValues(): ?array
    {
        return $this->draftValues;
    }

    /** @return array<string, string>|null */
    public function getDraftDigests(): ?array
    {
        return $this->draftDigests;
    }

    /** @return array<string, mixed>|null */
    public function getPublishedValues(): ?array
    {
        return $this->publishedValues;
    }

    /** @return array<string, string>|null */
    public function getPublishedDigests(): ?array
    {
        return $this->publishedDigests;
    }

    /**
     * Draft-or-published, the same rule `CoreBlockDataResolver` applies to
     * `Block.data`, so a translation never lags a mode behind its source.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveValues(): array
    {
        return $this->draftValues ?? $this->publishedValues ?? [];
    }

    /** @return array<string, string> */
    public function getEffectiveDigests(): array
    {
        // Paired with getEffectiveValues() on purpose: digests are only
        // meaningful against the values they were captured with.
        return ($this->draftValues !== null ? $this->draftDigests : $this->publishedDigests) ?? [];
    }

    /**
     * Value and digest move together — a drifting digest is worse than none.
     * The first write copies the published payload into the draft.
     *
     * @see docs/internals/i18n.md#the-digest-is-the-whole-mechanism
     */
    public function setDraftValue(string $path, mixed $value, string $sourceDigest): self
    {
        $this->draftValues ??= $this->publishedValues ?? [];
        $this->draftDigests ??= $this->publishedDigests ?? [];

        $this->draftValues[$path] = $value;
        $this->draftDigests[$path] = $sourceDigest;
        $this->touch();

        return $this;
    }

    /**
     * Drops a field from the draft, which renders as a fallback to the source
     * rather than as an empty string.
     */
    public function removeDraftValue(string $path): self
    {
        $this->draftValues ??= $this->publishedValues ?? [];
        $this->draftDigests ??= $this->publishedDigests ?? [];

        unset($this->draftValues[$path], $this->draftDigests[$path]);
        $this->touch();

        return $this;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $digests
     */
    public function setDraftPayload(array $values, array $digests): self
    {
        $this->draftValues = $values;
        $this->draftDigests = $digests;
        $this->touch();

        return $this;
    }

    /**
     * @param array<string, mixed>  $values
     * @param array<string, string> $digests
     */
    public function setPublishedPayload(array $values, array $digests): self
    {
        $this->publishedValues = $values;
        $this->publishedDigests = $digests;
        $this->touch();

        return $this;
    }

    /** Promote the pending edit. Mirrors {@see Block::publish()}. */
    public function publish(): void
    {
        if ($this->draftValues === null) {
            return;
        }

        $this->publishedValues = $this->draftValues;
        $this->publishedDigests = $this->draftDigests;
        $this->draftValues = null;
        $this->draftDigests = null;
        $this->touch();
    }

    /** Drop the pending edit. Mirrors {@see Block::revertDraft()}. */
    public function revertDraft(): void
    {
        $this->draftValues = null;
        $this->draftDigests = null;
        $this->touch();
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->draftValues !== null;
    }

    /**
     * True when the row holds nothing in either state — the signal the store
     * uses to delete it rather than keep an empty row around forever.
     */
    public function isEmpty(): bool
    {
        return ($this->draftValues ?? []) === [] && ($this->publishedValues ?? []) === [];
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
