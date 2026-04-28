<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

/**
 * The accumulated visual effect a {@see SectionDecoratorInterface} applies
 * to a section's outer markup: extra CSS classes, HTML attributes and
 * inline style declarations.
 *
 * Immutable; merge two decorations into a new one with {@see merge()}.
 */
final class SectionDecoration
{
    /**
     * @param list<string>            $classes
     * @param array<string, string>   $attributes
     * @param array<string, string>   $inlineStyles  CSS property => value
     */
    public function __construct(
        public readonly array $classes = [],
        public readonly array $attributes = [],
        public readonly array $inlineStyles = [],
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            classes: [...$this->classes, ...$other->classes],
            attributes: [...$this->attributes, ...$other->attributes],
            inlineStyles: [...$this->inlineStyles, ...$other->inlineStyles],
        );
    }

    public function styleString(): string
    {
        $out = '';
        foreach ($this->inlineStyles as $prop => $val) {
            $out .= $prop . ':' . $val . ';';
        }

        return $out;
    }

    public function classString(): string
    {
        return implode(' ', array_unique(array_filter($this->classes)));
    }
}
