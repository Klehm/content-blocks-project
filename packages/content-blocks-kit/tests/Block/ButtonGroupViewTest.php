<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Kit\Block\ButtonGroupBlock;
use ContentBlocks\Kit\RichText\RichTextSanitizerFactory;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use ContentBlocks\Kit\Twig\SafeContentExtension;
use ContentBlocks\Twig\ColorToneExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ButtonGroupViewTest extends TestCase
{
    public function testDefaultsRenderTwoButtonsInOneRow(): void
    {
        $html = $this->render([]);

        $this->assertSame(1, substr_count($html, 'class="cb-kit-btn-group cb-kit-btn-group--start"'));
        $this->assertSame(2, substr_count($html, 'class="cb-kit-btn '));
        $this->assertStringContainsString('cb-kit-btn--primary cb-kit-btn--md', $html);
        $this->assertStringContainsString('cb-kit-btn--outline cb-kit-btn--md', $html);
    }

    /** Size and alignment belong to the row; the style to each button. */
    public function testSharedSizeAlignmentAndPerButtonOptions(): void
    {
        $html = $this->render([
            'size' => 'lg',
            'align' => 'center',
            'stackOnMobile' => true,
            'items' => [
                ['text' => 'Shop', 'url' => '/shop', 'variant' => 'secondary', 'newTab' => true],
                ['text' => 'Call', 'url' => 'tel:+33100000000', 'variant' => 'link'],
            ],
        ]);

        $this->assertStringContainsString('cb-kit-btn-group--center cb-kit-btn-group--stack', $html);
        $this->assertMatchesRegularExpression('#cb-kit-btn--secondary cb-kit-btn--lg"\s+href="/shop" target="_blank" rel="noopener noreferrer">Shop</a>#', $html);
        $this->assertMatchesRegularExpression('#cb-kit-btn--link cb-kit-btn--lg"\s+href="tel:\+33100000000">Call</a>#', $html);
    }

    /** An entry being typed has no label yet: it is skipped, not a blank. */
    public function testButtonsWithoutTextAreSkipped(): void
    {
        $this->assertSame(1, substr_count($this->render(['items' => [['text' => ' '], ['text' => 'Go']]]), '<a '));
        $this->assertSame('', trim($this->render(['items' => [['text' => '']]])));
    }

    /** `max_items` caps the row at validation, 3 unless the host says. */
    public function testTheItemCountIsCappedByTheOption(): void
    {
        $item = ['text' => 'X', 'url' => '', 'variant' => 'primary', 'newTab' => false];

        $this->assertTrue($this->submit(new ButtonGroupBlock(), array_fill(0, 3, $item)));
        $this->assertFalse($this->submit(new ButtonGroupBlock(), array_fill(0, 4, $item)));
        $this->assertTrue($this->submit(new ButtonGroupBlock(['max_items' => 4]), array_fill(0, 4, $item)));
        $this->assertFalse($this->submit(new ButtonGroupBlock(), []));
    }

    /** @param list<array<string, mixed>> $items */
    private function submit(ButtonGroupBlock $block, array $items): bool
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new \ContentBlocks\Form\Extension\TranslatableFieldTypeExtension())
            ->addTypeExtension(new \ContentBlocks\Form\Extension\CollectionFoldTypeExtension())
            ->getFormFactory();
        $builder = $factory->createBuilder(FormType::class, $block->getDefaultData());
        $block->buildForm($builder, $block->getDefaultData());
        $form = $builder->getForm();
        $form->submit(['items' => $items, 'size' => 'md', 'align' => 'start']);

        return $form->isValid();
    }

    /** @param array<string, mixed> $data */
    private function render(array $data): string
    {
        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'ContentBlocksKit');
        $env = new Environment($loader, ['strict_variables' => true]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new SafeContentExtension(RichTextSanitizerFactory::create()));
        $env->addExtension(new ColorToneExtension());

        return $env->render('@ContentBlocksKit/block/button_group/view.html.twig', [
            'data' => $data + (new ButtonGroupBlock())->getDefaultData(),
        ]);
    }
}
