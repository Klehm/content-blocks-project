<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

/**
 * Block-side mirror of {@see \ContentBlocks\Section\SectionDecoration} —
 * accumulated visual effect a {@see BlockDecoratorInterface} applies to
 * a block's outer markup.
 *
 * Immutable; combine two decorations with {@see merge()}.
 */
final class BlockDecoration
{
    /**
     * @param list<string>          $classes
     * @param array<string, string> $attributes
     * @param array<string, string> $inlineStyles CSS property => value
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
