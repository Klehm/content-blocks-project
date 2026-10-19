<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

use Symfony\Contracts\Translation\TranslatableInterface;

final class BlockTypeRegistry
{
    /** @var array<string, BlockTypeInterface> */
    private array $blockTypes = [];

    /** @var array<string, string|null> */
    private array $categories = [];

    public function register(BlockTypeInterface $blockType, ?string $category = null): void
    {
        $this->blockTypes[$blockType->getType()] = $blockType;
        $this->categories[$blockType->getType()] = $category;
    }

    /**
     * The picker category the block type declared, or null. Where it lands
     * is {@see BlockCategoryRegistry::categoryOf()}'s call.
     */
    public function getCategory(string $type): ?string
    {
        return $this->categories[$type] ?? null;
    }

    public function get(string $type): BlockTypeInterface
    {
        if (!isset($this->blockTypes[$type])) {
            throw new \InvalidArgumentException(sprintf('Block type "%s" is not registered.', $type));
        }

        return $this->blockTypes[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->blockTypes[$type]);
    }

    /** @return array<string, BlockTypeInterface> */
    public function all(): array
    {
        return $this->blockTypes;
    }

    /**
     * @return array<string, string|TranslatableInterface> type => label.
     *   The label may be a plain string or a TranslatableInterface depending
     *   on what the BlockType returned — callers translate via the
     *   appropriate path.
     */
    public function getChoices(): array
    {
        $choices = [];
        foreach ($this->blockTypes as $type => $blockType) {
            $choices[$type] = $blockType->getLabel();
        }

        return $choices;
    }
}
