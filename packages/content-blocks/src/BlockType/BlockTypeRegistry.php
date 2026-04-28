<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

use Symfony\Contracts\Translation\TranslatableInterface;

final class BlockTypeRegistry
{
    /** @var array<string, BlockTypeInterface> */
    private array $blockTypes = [];

    public function register(BlockTypeInterface $blockType): void
    {
        $this->blockTypes[$blockType::getType()] = $blockType;
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
            $choices[$type] = $blockType::getLabel();
        }

        return $choices;
    }
}
