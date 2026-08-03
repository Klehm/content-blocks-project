<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Block;

use ContentBlocks\Block\CollectionItemIds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

/**
 * `_id` exists so that per-entry information survives a reorder. These tests
 * pin the two halves of that promise: an id is minted where one is missing, and
 * an existing id is never reassigned.
 */
final class CollectionItemIdsTest extends TestCase
{
    public function testAnEntryWithoutAnIdGetsOne(): void
    {
        $data = ['items' => [['label' => 'One'], ['label' => 'Two']]];

        $out = $this->backfill($data);

        $this->assertArrayHasKey('_id', $out['items'][0]);
        $this->assertArrayHasKey('_id', $out['items'][1]);
        $this->assertNotSame($out['items'][0]['_id'], $out['items'][1]['_id']);
    }

    public function testAnExistingIdIsLeftAlone(): void
    {
        // The load-bearing half: re-minting on every save would defeat the
        // entire point, since the id is what per-entry data is keyed by.
        $data = ['items' => [['_id' => 'keep-me', 'label' => 'One']]];

        $this->assertSame('keep-me', $this->backfill($data)['items'][0]['_id']);
    }

    public function testIdsFollowTheirEntryThroughAReorder(): void
    {
        $data = $this->backfill(['items' => [['label' => 'A'], ['label' => 'B']]]);
        [$idA, $idB] = [$data['items'][0]['_id'], $data['items'][1]['_id']];

        // What BlockComponent::moveCollectionItem does to formValues.
        $data['items'] = [$data['items'][1], $data['items'][0]];

        $out = $this->backfill($data);

        $this->assertSame($idB, $out['items'][0]['_id'], 'B kept its id in its new position');
        $this->assertSame($idA, $out['items'][1]['_id'], 'A kept its id in its new position');
        $this->assertSame('B', $out['items'][0]['label']);
    }

    public function testNestedCollectionsAreCovered(): void
    {
        // The kit's table block: rows of cells. Two positional levels, so both
        // need identity.
        $data = ['rows' => [['cells' => [['content' => 'a1'], ['content' => 'a2']]]]];

        $out = $this->backfill($data, nested: true);

        $this->assertArrayHasKey('_id', $out['rows'][0]);
        $this->assertArrayHasKey('_id', $out['rows'][0]['cells'][0]);
        $this->assertArrayHasKey('_id', $out['rows'][0]['cells'][1]);
        $this->assertNotSame(
            $out['rows'][0]['cells'][0]['_id'],
            $out['rows'][0]['cells'][1]['_id'],
        );
    }

    public function testNonCollectionFieldsAreUntouched(): void
    {
        // `styling` is a compound sub-form, not a collection — it must not
        // sprout an id, and neither must a plain scalar field.
        $data = ['title' => 'Hello', 'items' => [], 'styling' => ['gap' => 4]];

        $out = $this->backfill($data);

        $this->assertSame(['title' => 'Hello', 'items' => [], 'styling' => ['gap' => 4]], $out);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function backfill(array $data, bool $nested = false): array
    {
        $factory = Forms::createFormFactory();
        $form = $nested ? $this->nestedForm($factory, $data) : $this->flatForm($factory, $data);

        return (new CollectionItemIds())->backfill($form, $form->getData());
    }

    /** @param array<string, mixed> $data */
    private function flatForm(FormFactoryInterface $factory, array $data): FormInterface
    {
        return $factory->createBuilder(FormType::class, $data, ['data_class' => null])
            ->add('title', TextType::class, ['required' => false])
            ->add('items', CollectionType::class, [
                'entry_type' => ItemFixtureType::class,
                'allow_add' => true,
            ])
            ->add('styling', FormType::class, ['required' => false])
            ->getForm();
    }

    /** @param array<string, mixed> $data */
    private function nestedForm(FormFactoryInterface $factory, array $data): FormInterface
    {
        return $factory->createBuilder(FormType::class, $data, ['data_class' => null])
            ->add('rows', CollectionType::class, [
                'entry_type' => RowFixtureType::class,
                'allow_add' => true,
            ])
            ->getForm();
    }
}

final class ItemFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['required' => false]);
    }
}

final class RowFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('cells', CollectionType::class, [
            'entry_type' => CellFixtureType::class,
            'allow_add' => true,
        ]);
    }
}

final class CellFixtureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', TextType::class, ['required' => false]);
    }
}
