<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Field;

use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Extension\BlockFormExtensionInterface;
use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Form\Type\BlockFormType;
use ContentBlocks\I18n\Field\FieldMetadataReader;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The metadata cache is a per-request cache, and a worker runtime is where that
 * distinction stops being pedantic.
 *
 * A block's form is not a constant: a host's
 * {@see BlockFormExtensionInterface} may add a field for one role and not
 * another, and the reader's own cache key deliberately ignores `$data`, so the
 * first call of the process pins a shape. Under PHP-FPM that pin lasts one
 * request. Under FrankenPHP it would last until the worker is recycled — the
 * page would show whichever field set the first visitor happened to produce.
 */
final class FieldMetadataReaderResetTest extends TestCase
{
    public function testItIsResettable(): void
    {
        $this->assertInstanceOf(ResetInterface::class, $this->readerFor(new ToggleableExtension()));
    }

    public function testTheCacheHoldsWithinARequestAndIsClearedBetweenTwo(): void
    {
        $extension = new ToggleableExtension();
        $reader = $this->readerFor($extension);

        $this->assertArrayNotHasKey('perRole', $reader->forBlockType('fixture'));

        // Same process, next request: the host's extension now contributes a
        // field. Without a reset the reader would keep answering with the shape
        // it memoized for the previous visitor.
        $extension->enabled = true;

        $this->assertArrayNotHasKey('perRole', $reader->forBlockType('fixture'), 'The cache should hold while the request lasts.');

        $reader->reset();

        $this->assertArrayHasKey('perRole', $reader->forBlockType('fixture'));
    }

    private function readerFor(BlockFormExtensionInterface $extension): FieldMetadataReader
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new BlockFormType(new BlockFormExtensionCollection([[$extension, ['*']]])))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->getFormFactory();

        return new FieldMetadataReader(CatalogFactory::registry(), $factory);
    }
}

/**
 * A host extension whose contribution depends on something outside the block.
 */
final class ToggleableExtension implements BlockFormExtensionInterface
{
    public bool $enabled = false;

    public function buildForm(FormBuilderInterface $builder, array $data, string $blockType): void
    {
        if ($this->enabled) {
            $builder->add('perRole', TextType::class, ['label' => 'Per role']);
        }
    }
}
