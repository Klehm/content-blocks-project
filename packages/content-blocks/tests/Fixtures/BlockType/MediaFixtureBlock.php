<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures\BlockType;

use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\AsContentBlock;
use Symfony\Component\Form\FormBuilderInterface;

#[AsContentBlock(priority: 10, category: 'media')]
final class MediaFixtureBlock extends AbstractBlockType
{
    public function getType(): string
    {
        return 'fixture_media';
    }

    public function getLabel(): string
    {
        return 'Media fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
    }

    public function getDefaultData(): array
    {
        return [];
    }
}
