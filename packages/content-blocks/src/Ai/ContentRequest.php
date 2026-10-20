<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Schema\ContentSchema;

/**
 * Sections to generate from an editor's prompt, with the content schema that
 * says what the area accepts and the sections already on the page.
 */
final class ContentRequest
{
    /**
     * @param string                     $action   {@see AiAction::SECTION}
     *                                             or {@see AiAction::PAGE}
     * @param list<array<string, mixed>> $existing the area's draft sections,
     *                                             in the output shape
     * @param int                        $maxSections what the area will take
     * @param string|null                $brief    what the host says about
     *                                             the page
     */
    public function __construct(
        public readonly string $action,
        public readonly string $prompt,
        public readonly ContentSchema $schema,
        public readonly string $locale,
        public readonly array $existing = [],
        public readonly int $maxSections = 1,
        public readonly ?string $brief = null,
    ) {
    }
}
