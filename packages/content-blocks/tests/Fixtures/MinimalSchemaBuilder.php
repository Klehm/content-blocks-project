<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Schema\ContentSchemaBuilder;
use ContentBlocks\Schema\FormSchemaMapper;
use ContentBlocks\Schema\SectionTemplateExamples;
use ContentBlocks\Section\SectionStyleRegistry;
use Symfony\Component\Form\Forms;

/**
 * A schema builder over the given registry and the core form types, for
 * tests about what surrounds the builder, not the builder itself.
 */
final class MinimalSchemaBuilder
{
    public static function make(
        BlockTypeRegistry $registry = new BlockTypeRegistry(),
        ?SectionTemplateExamples $examples = null,
    ): ContentSchemaBuilder {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addType(new SectionSettingsType(new SectionStyleRegistry()))
            ->addType(new PaletteColorType())
            ->getFormFactory();

        return new ContentSchemaBuilder($registry, $factory, new FormSchemaMapper(), examples: $examples);
    }
}
