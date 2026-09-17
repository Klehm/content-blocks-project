<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The payload every translation row carries, whatever it translates: a flat
 * path → value map with its source digests, draft and published.
 *
 * @see docs/internals/i18n.md#the-digest-is-the-whole-mechanism
 */
trait TranslationPayload
{
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

    /** Promote the pending edit. Mirrors `Block::publish()`. */
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

    /** Drop the pending edit. Mirrors `Block::revertDraft()`. */
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
