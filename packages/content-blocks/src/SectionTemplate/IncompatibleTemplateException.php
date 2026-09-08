<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Thrown when a template had blocks and none of their types is registered any
 * more, so there is nothing left to insert.
 *
 * @see docs/internals/section-templates.md#two-unreadable-template-cases
 */
final class IncompatibleTemplateException extends \RuntimeException
{
    /**
     * @param list<string> $missingTypes type ids absent from the registry
     */
    public function __construct(private readonly array $missingTypes)
    {
        parent::__construct(sprintf(
            'Section template requires unregistered block type(s): %s.',
            implode(', ', $missingTypes),
        ));
    }

    /** @return list<string> */
    public function getMissingTypes(): array
    {
        return $this->missingTypes;
    }
}
