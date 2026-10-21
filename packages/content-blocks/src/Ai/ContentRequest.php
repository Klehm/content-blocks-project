<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Schema\ContentSchema;

/**
 * Sections to generate or to revise from an editor's prompt, with the content
 * schema that says what the area accepts and the sections already there.
 */
final class ContentRequest
{
    /**
     * @param string                     $action   one of
     *                                             {@see AiAction::CONTENT}
     * @param list<array<string, mixed>> $existing the area's draft sections,
     *                                             in the output shape
     * @param int                        $maxSections what the area will take
     * @param string|null                $brief    what the host says about
     *                                             the page
     * @param list<array<string, mixed>> $current  for an edit: the sections
     *                                             to revise, same shape; the
     *                                             answer takes their place
     */
    public function __construct(
        public readonly string $action,
        public readonly string $prompt,
        public readonly ContentSchema $schema,
        public readonly string $locale,
        public readonly array $existing = [],
        public readonly int $maxSections = 1,
        public readonly ?string $brief = null,
        public readonly array $current = [],
    ) {
    }
}
