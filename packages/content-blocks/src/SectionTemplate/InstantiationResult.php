<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

use ContentBlocks\Entity\Section;

/**
 * Outcome of instantiating a section template: the detached draft Section ready
 * to be attached to a ContentArea, plus any non-blocking warnings surfaced
 * during the build (e.g. a stored field that the block type no longer defines).
 */
final class InstantiationResult
{
    /**
     * @param list<array{blockType: string, unknownKeys: list<string>}> $warnings
     */
    public function __construct(
        public readonly Section $section,
        public readonly array $warnings = [],
    ) {
    }
}
