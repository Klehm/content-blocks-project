<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\BlockType;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock(priority: 20)]
final class UncategorizedFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'fixture_plain';
    }

    public function getLabel(): string
    {
        return 'Plain fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}
