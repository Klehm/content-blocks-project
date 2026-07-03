<?php

declare(strict_types=1);

namespace ContentBlocks\SectionTemplate;

/**
 * Thrown when a section template cannot be instantiated because one or more of
 * its block types is no longer registered in the BlockTypeRegistry.
 *
 * Unknown block types are a hard stop (unlike missing *fields*, which only warn):
 * a block whose type has vanished has no form, no renderer and no default data,
 * so silently inserting it would drop content and confuse the editor.
 */
final class IncompatibleTemplateException extends \RuntimeException
{
    /**
     * @param list<string> $missingTypes Block-type identifiers absent from the registry
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
