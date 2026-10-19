<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * The groups of the block picker: the built-ins merged with the host's
 * `content_blocks.picker`, and which group each block type lands in.
 *
 * @see docs/guide/custom-blocks.md#picker-categories
 */
final class BlockCategoryRegistry
{
    /** Where a block that declares no category, or an unknown one, lands. */
    public const OTHER = 'other';

    /**
     * Name => label (translation key, content_blocks domain), in picker order.
     *
     * @var array<string, string>
     */
    public const BUILT_IN = [
        'text' => 'cb.picker.category.text',
        'media' => 'cb.picker.category.media',
        'layout' => 'cb.picker.category.layout',
        'actions' => 'cb.picker.category.actions',
        'advanced' => 'cb.picker.category.advanced',
        self::OTHER => 'cb.picker.category.other',
    ];

    /** @var array<string, string> */
    private readonly array $categories;

    /**
     * @param array<string, string>|null $categories  see {@see self::resolve()}
     * @param array<string, string>      $assignments type => category
     */
    public function __construct(?array $categories = null, private readonly array $assignments = [])
    {
        $this->categories = $categories ?? self::resolve([]);
    }

    /**
     * The host's categories first, in the order it lists them, then the
     * built-ins it did not list. A null label keeps the built-in one.
     *
     * @param array<string, string|null> $configured
     *
     * @return array<string, string>
     */
    public static function resolve(array $configured): array
    {
        $resolved = [];
        foreach ($configured as $name => $label) {
            $label ??= self::BUILT_IN[$name] ?? null;
            if ($label === null || $label === '') {
                throw new \InvalidArgumentException(sprintf('The picker category "%s" needs a label.', $name));
            }
            $resolved[(string) $name] = $label;
        }

        return $resolved + self::BUILT_IN;
    }

    /** @return array<string, string> name => label, in picker order */
    public function all(): array
    {
        return $this->categories;
    }

    public function has(string $name): bool
    {
        return isset($this->categories[$name]);
    }

    /** The host's assignment wins over what the block type declares. */
    public function categoryOf(string $type, ?string $declared): string
    {
        $category = $this->assignments[$type] ?? $declared;

        return $category !== null && $this->has($category) ? $category : self::OTHER;
    }

    /**
     * An assignment naming a type that nearly matches a registered one is a
     * typo; one that matches nothing may name a disabled block.
     *
     * @param list<string> $registered
     */
    public function assertAssignments(array $registered): void
    {
        foreach (array_keys($this->assignments) as $type) {
            $type = (string) $type;
            if (\in_array($type, $registered, true)) {
                continue;
            }
            $close = array_filter($registered, static fn (string $t): bool => levenshtein($type, $t) <= 2);
            if ($close !== []) {
                $message = 'Unknown block type "%s" under "content_blocks.picker.blocks"'
                    . ' (did you mean "%s"?).';

                throw new \InvalidArgumentException(sprintf($message, $type, implode('", "', $close)));
            }
        }
    }
}
