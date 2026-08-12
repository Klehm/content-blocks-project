<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Fixtures;

use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\I18n\Field\FieldMetadataReader;
use ContentBlocks\I18n\Field\TranslatableFieldCatalog;
use ContentBlocks\Translation\TranslatableFields;
use ContentBlocks\Translation\TranslatableFieldsInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;

/**
 * Builds the real catalog against the real core reader, on a standalone Symfony
 * Forms factory.
 *
 * Real rather than mocked on purpose: the whole design rests on the core's
 * `cb_translatable` tags and this package's metadata walk producing the *same*
 * path patterns, and a mock would happily let those drift apart.
 */
final class CatalogFactory
{
    public static function create(): TranslatableFieldCatalog
    {
        $registry = self::registry();
        $factory = self::formFactory();

        return new TranslatableFieldCatalog(
            new TranslatableFields($registry, $factory),
            new FieldMetadataReader($registry, $factory),
        );
    }

    public static function translatableFields(): TranslatableFieldsInterface
    {
        return new TranslatableFields(self::registry(), self::formFactory());
    }

    public static function registry(): BlockTypeRegistry
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new TranslatableFixtureBlock());

        return $registry;
    }

    public static function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->getFormFactory();
    }
}
