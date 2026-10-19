<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\BlockType;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock(category: 'medai')]
final class TypoCategoryFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'fixture_typo';
    }

    public function getLabel(): string
    {
        return 'Typo fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}
