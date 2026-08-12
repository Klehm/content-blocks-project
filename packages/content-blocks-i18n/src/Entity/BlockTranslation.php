<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Entity;

use ContentBlocks\Entity\Block;
use ContentBlocks\I18n\Repository\BlockTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One block's values in one target locale.
 *
 * ---- Why a side table ----
 *
 * The alternative was a locale envelope inside `Block.data`, which rides along
 * every clone/export/import for free. It was rejected in the schema spike for
 * one reason: an envelope is opaque. "Which pages are missing German?" would
 * mean deserializing every block's JSON, so there could be no progress view and
 * no filtering — and a multilingual site is run from exactly that view.
 *
 * The cost is the mirror image: every flow that *duplicates* a block has to be
 * taught to duplicate its rows (see
 * {@see \ContentBlocks\I18n\Lifecycle\TranslationCloneObserver}), and the render
 * path needs a prefetch to avoid an N+1.
 *
 * ---- Shape ----
 *
 * Values are a **flat map of {@see \ContentBlocks\I18n\Field\FieldPath} → value**,
 * not a mirror of the block's data tree:
 *
 *     {"title": "Bienvenue", "items[9f2c1a].label": "Livraison rapide"}
 *
 * Flat means counting translated fields is `count()`, and a per-field merge is a
 * loop rather than a recursive diff. Collection entries are addressed by their
 * `_id`, so reordering the cards in the source locale does not shuffle the
 * French.
 *
 * ---- Draft / published ----
 *
 * Mirrors {@see Block} exactly: `draftValues === null` means "no pending
 * translation edit", and the effective payload is draft-or-published in PREVIEW,
 * published-only in PUBLIC. Translations therefore go live through the same
 * Publish button as the content they translate — which is the point. A French
 * heading must not appear on the public site while the English heading it was
 * written against is still an unpublished draft.
 *
 * ---- Digests ----
 *
 * Next to each value sits a digest of the **source** text it was translated
 * from. That is the whole staleness mechanism: re-hash the source today, compare,
 * and a mismatch means "the English changed after this French was written".
 * Kept per state so a published translation can still be reported as stale after
 * its draft has been cleared by a publish.
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
     * CASCADE rather than a lifecycle listener: a deleted block has no
     * translations by definition, and the database is the only place that can
     * guarantee it without every delete path opting in.
     */
    #[ORM\ManyToOne(targetEntity: Block::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Block $block = null;

    /** BCP 47 tag as the host spells it — `fr`, `pt_BR`, `zh-Hant`. Stored verbatim. */
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
     * The payload a reader should use: the in-flight edit if there is one,
     * otherwise what was published. Same rule as
     * {@see \ContentBlocks\Rendering\CoreBlockDataResolver} applies to
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
     * Writes one field, together with the digest of the source text it was
     * translated from.
     *
     * Value and digest move together in a single call because a digest that
     * drifts from its value is worse than no digest at all: it would report a
     * fresh translation as stale, or hide a genuinely outdated one.
     *
     * The first write copies the published payload into the draft, so a partial
     * edit does not silently unpublish every other field of the block.
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
     * Drops one field from the draft payload — "this locale has no translation
     * for this field", which renders as a fallback to the source rather than as
     * an empty string.
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

    /** Promote the pending translation edit to published. Mirrors {@see Block::publish()}. */
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

    /** Throw away the pending translation edit. Mirrors {@see Block::revertDraft()}. */
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
