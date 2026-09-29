<?php

declare(strict_types=1);

namespace ContentBlocks\Content;

/**
 * A structural change the model refuses. `reason` is a stable code, the one
 * the builder's endpoints answer with.
 */
final class ContentManipulationException extends \InvalidArgumentException
{
    public const UNKNOWN_BLOCK_TYPE = 'unknown_block_type';
    public const UNKNOWN_LAYOUT = 'unknown_layout';
    public const TOO_MANY_COLUMNS = 'too_many_columns';
    public const LAST_COLUMN = 'last_column';
    public const FOREIGN_TARGET = 'foreign_target';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownBlockType(string $type): self
    {
        return new self(self::UNKNOWN_BLOCK_TYPE, \sprintf('Unknown block type "%s".', $type));
    }

    public static function unknownLayout(string $layout): self
    {
        return new self(self::UNKNOWN_LAYOUT, \sprintf('Unknown or disabled section layout "%s".', $layout));
    }

    public static function tooManyColumns(int $max): self
    {
        return new self(self::TOO_MANY_COLUMNS, \sprintf('A section holds at most %d columns.', $max));
    }

    public static function lastColumn(): self
    {
        return new self(self::LAST_COLUMN, 'A section keeps at least one column.');
    }

    public static function foreignTarget(): self
    {
        return new self(self::FOREIGN_TARGET, 'A block moves within its own content area only.');
    }
}
