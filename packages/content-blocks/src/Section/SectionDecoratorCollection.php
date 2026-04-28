<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Iterates registered decorators in service-order, accumulating their
 * output into a single {@see SectionDecoration}.
 */
final class SectionDecoratorCollection
{
    /**
     * @param iterable<SectionDecoratorInterface> $decorators
     */
    public function __construct(
        private readonly iterable $decorators,
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function decorate(array $settings, Section $section): SectionDecoration
    {
        $result = new SectionDecoration();
        foreach ($this->decorators as $decorator) {
            $result = $result->merge($decorator->decorate($settings, $section));
        }

        return $result;
    }
}
