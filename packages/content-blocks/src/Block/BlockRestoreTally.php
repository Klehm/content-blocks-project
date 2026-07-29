<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Running tally of what a restore could and could not bring in, shared by the
 * two flows that replay stored content (area import, section-template insert).
 *
 * Plumbing, not a seam: it exists so the two services accumulate the same facts
 * under the same names instead of threading four by-reference parameters
 * through their builders. What reaches the caller is the flow's own result
 * object ({@see \ContentBlocks\Transfer\ImportResult},
 * {@see \ContentBlocks\SectionTemplate\InstantiationResult}).
 */
final class BlockRestoreTally
{
    private int $kept = 0;
    private int $skipped = 0;

    /** @var list<string> */
    private array $skippedTypes = [];

    /** @var list<array{blockType: string, unknownKeys: list<string>}> */
    private array $unknownFields = [];

    /** A block left out because its type is not registered here. */
    public function skip(string $blockType): void
    {
        ++$this->skipped;
        if (!in_array($blockType, $this->skippedTypes, true)) {
            $this->skippedTypes[] = $blockType;
        }
    }

    public function keep(): void
    {
        ++$this->kept;
    }

    /**
     * @param list<string> $keys empty is the normal case and records nothing
     */
    public function noteUnknownKeys(string $blockType, array $keys): void
    {
        if ($keys !== []) {
            $this->unknownFields[] = ['blockType' => $blockType, 'unknownKeys' => $keys];
        }
    }

    public function keptCount(): int
    {
        return $this->kept;
    }

    public function skippedCount(): int
    {
        return $this->skipped;
    }

    /** @return list<string> */
    public function skippedTypes(): array
    {
        return $this->skippedTypes;
    }

    /** @return list<array{blockType: string, unknownKeys: list<string>}> */
    public function unknownFields(): array
    {
        return $this->unknownFields;
    }
}
