<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\BlockType;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock(category: 'media')]
class MediaFixtureParentBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'fixture_media_parent';
    }

    public function getLabel(): string
    {
        return 'Media parent fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}
