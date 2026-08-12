<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Clipboard;

use ContentBlocks\Block\BlockDataDefaults;
use ContentBlocks\Block\CollectionItemIds;
use ContentBlocks\BlockType\AbstractBlockType;
use ContentBlocks\BlockType\BlockTypeRegistry;
use ContentBlocks\Clipboard\BlockDataReplayer;
use ContentBlocks\Form\Extension\BlockFormExtensionCollection;
use ContentBlocks\Form\Type\BlockFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The clipboard's whole safety argument lives here: a payload that came back
 * from `localStorage` is replayed through the block's own form, so what the
 * form does not declare never reaches `Block.data`.
 */
final class BlockDataReplayerTest extends TestCase
{
    public function testValidDataRoundTrips(): void
    {
        $result = $this->replayer()->replay('fixture', ['title' => 'Hello', 'size' => 'lg']);

        $this->assertSame('Hello', $result->data['title']);
        $this->assertSame('lg', $result->data['size']);
        $this->assertSame([], $result->droppedFields);
    }

    public function testAnUndeclaredKeyNeverReachesTheData(): void
    {
        // The tampered-payload case: localStorage is user-writable, so this is
        // the one an attacker actually reaches for.
        $result = $this->replayer()->replay('fixture', ['title' => 'Hello', 'onerror' => 'alert(1)']);

        $this->assertArrayNotHasKey('onerror', $result->data);
        $this->assertSame('Hello', $result->data['title'], 'the legitimate field still lands');
    }

    public function testAnInvalidValueFallsBackToTheTypeDefaultAndIsReported(): void
    {
        $result = $this->replayer()->replay('fixture', ['title' => 'Hello', 'size' => 'enormous']);

        $this->assertSame('sm', $result->data['size'], 'reset to the type default');
        $this->assertSame(['size'], $result->droppedFields);
        $this->assertSame('Hello', $result->data['title'], 'a sibling field is not punished for it');
    }

    public function testAValueFailingAConstraintIsDroppedToo(): void
    {
        // Not a transformation failure — a plain Assert on the field, which is
        // how a block type declares what its data may hold.
        $result = $this->replayer()->replay('fixture', ['title' => str_repeat('x', 50)]);

        $this->assertSame('', $result->data['title']);
        $this->assertSame(['title'], $result->droppedFields);
    }

    public function testTwoInvalidFieldsAreBothReported(): void
    {
        $result = $this->replayer()->replay('fixture', [
            'title' => str_repeat('x', 50),
            'size' => 'enormous',
        ]);

        $names = $result->droppedFields;
        sort($names);
        $this->assertSame(['size', 'title'], $names);
    }

    public function testADeclaredButNonEditableKeyIsKeptVerbatim(): void
    {
        // Union rule, same as BlockDataKeys: a key the type declares in
        // getDefaultData() without exposing a field is part of its data
        // contract, and no form child can vouch for it.
        $result = $this->replayer()->replay('fixture', ['title' => 'Hi', 'variant' => 'compact']);

        $this->assertSame('compact', $result->data['variant']);
    }

    public function testAReservedKeyIsStripped(): void
    {
        $result = $this->replayer()->replay('fixture', ['title' => 'Hi', '_smuggled' => 'x']);

        $this->assertArrayNotHasKey('_smuggled', $result->data);
    }

    public function testCollectionEntriesComeOutWithFreshIds(): void
    {
        $result = $this->replayer()->replay('fixture', [
            'items' => [['label' => 'one', '_id' => 'from-the-source'], ['label' => 'two']],
        ]);

        $ids = array_column($result->data['items'], CollectionItemIds::KEY);
        $this->assertCount(2, $ids);
        $this->assertNotContains('from-the-source', $ids, 'a pasted block is a new block');
        $this->assertNotEquals($ids[0], $ids[1]);
        $this->assertSame(['one', 'two'], array_column($result->data['items'], 'label'));
    }

    public function testAnExpandedChoiceRoundTrips(): void
    {
        // Regression: an expanded choice is compound (one child per option) but
        // a browser posts the *value*, and ChoiceType's submit path reads it
        // that way. Handing it a per-child map used to blow up mid-paste.
        $result = $this->replayer()->replay('fixture', ['align' => 'right']);

        $this->assertSame('right', $result->data['align']);
        $this->assertSame([], $result->droppedFields);
    }

    public function testAnInvalidExpandedChoiceIsStillCaught(): void
    {
        $result = $this->replayer()->replay('fixture', ['align' => 'diagonal']);

        $this->assertSame('left', $result->data['align'], 'reset to the type default');
        $this->assertSame(['align'], $result->droppedFields);
    }

    public function testTheStylingSubtreeSurvives(): void
    {
        $result = $this->replayer()->replay('fixture', ['styling' => ['backgroundColor' => '#eb0540']]);

        $this->assertSame('#eb0540', $result->data['styling']['backgroundColor']);
    }

    public function testAnUnregisteredTypeIsTheCallerProblem(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->replayer()->replay('gone', []);
    }

    private function replayer(): BlockDataReplayer
    {
        $registry = new BlockTypeRegistry();
        $registry->register(new ReplayFixtureBlock());

        $factory = Forms::createFormFactoryBuilder()
            // Constraints only run with the validator extension — without it
            // this would test transformation failures alone.
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new BlockFormType(new BlockFormExtensionCollection()))
            ->getFormFactory();

        return new BlockDataReplayer($registry, $factory, new BlockDataDefaults(), new CollectionItemIds());
    }
}

final class ReplayFixtureBlock extends AbstractBlockType
{
    public static function getType(): string
    {
        return 'fixture';
    }

    public static function getLabel(): string
    {
        return 'Fixture';
    }

    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        $builder
            ->add('title', TextType::class, [
                'required' => false,
                'data' => $data['title'] ?? '',
                'constraints' => [new Assert\Length(max: 10)],
            ])
            ->add('size', ChoiceType::class, [
                'required' => false,
                'choices' => ['Small' => 'sm', 'Large' => 'lg'],
                'data' => $data['size'] ?? 'sm',
            ])
            ->add('align', ChoiceType::class, [
                'required' => false,
                'expanded' => true,
                'choices' => ['Left' => 'left', 'Right' => 'right'],
                'data' => $data['align'] ?? 'left',
            ])
            ->add('items', CollectionType::class, [
                'required' => false,
                'entry_type' => ReplayEntryType::class,
                'allow_add' => true,
                'allow_delete' => true,
            ]);
    }

    public function getDefaultData(): array
    {
        // `variant` is declared but never editable — the union case.
        return ['title' => '', 'size' => 'sm', 'align' => 'left', 'items' => [], 'variant' => 'default'];
    }
}

final class ReplayEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, ['required' => false]);
    }
}
