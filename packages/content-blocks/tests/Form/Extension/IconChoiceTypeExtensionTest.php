<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Extension;

use ContentBlocks\Form\Extension\IconChoiceTypeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

final class IconChoiceTypeExtensionTest extends TestCase
{
    public function testAskingForIconsExpandsTheChoice(): void
    {
        $form = $this->factory()->createNamed('corner', ChoiceType::class, null, [
            'choices' => ['Top left' => 'tl', 'Top right' => 'tr'],
            'cb_icons' => ['tl' => 'corner-tl', 'tr' => 'corner-tr'],
        ]);

        $this->assertTrue($form->getConfig()->getOption('expanded'));
    }

    public function testThePrefixSitsJustBeforeTheFieldsOwn(): void
    {
        $view = $this->factory()->createNamed('corner', ChoiceType::class, null, [
            'choices' => ['Top left' => 'tl'],
            'cb_icons' => ['tl' => 'corner-tl'],
        ])->createView();

        $prefixes = $view->vars['block_prefixes'];
        $this->assertSame(IconChoiceTypeExtension::BLOCK_PREFIX, $prefixes[\count($prefixes) - 2]);
        $this->assertSame('_corner', end($prefixes));
    }

    public function testTheLayoutOptionsReachTheView(): void
    {
        $view = $this->factory()->createNamed('sides', ChoiceType::class, null, [
            'choices' => ['Top' => 'top', 'Bottom' => 'bottom'],
            'multiple' => true,
            'cb_icons' => ['top' => 'side-top', 'bottom' => ''],
            'cb_icon_layout' => 'grid',
            'cb_icon_columns' => 2,
            'cb_icon_labels' => true,
        ])->createView();

        $this->assertSame(['top' => 'side-top', 'bottom' => null], $view->vars['cb_icons']);
        $this->assertSame('grid', $view->vars['cb_icon_layout']);
        $this->assertSame(2, $view->vars['cb_icon_columns']);
        $this->assertTrue($view->vars['cb_icon_labels']);
    }

    public function testWithoutIconsTheChoiceIsLeftAlone(): void
    {
        $form = $this->factory()->createNamed('size', ChoiceType::class, null, [
            'choices' => ['S' => 's'],
        ]);
        $view = $form->createView();

        $this->assertFalse($form->getConfig()->getOption('expanded'));
        $this->assertNotContains(IconChoiceTypeExtension::BLOCK_PREFIX, $view->vars['block_prefixes']);
        $this->assertArrayNotHasKey('cb_icons', $view->vars);
    }

    public function testAnUnknownLayoutIsRefused(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory()->create(ChoiceType::class, null, [
            'choices' => ['S' => 's'],
            'cb_icons' => ['s' => 'auto'],
            'cb_icon_layout' => 'carousel',
        ]);
    }

    public function testDecoratingTwiceAddsThePrefixOnce(): void
    {
        $view = $this->factory()->createNamed('size', ChoiceType::class, null, [
            'choices' => ['S' => 's'],
            'expanded' => true,
        ])->createView();

        IconChoiceTypeExtension::decorate($view, ['s' => 'auto']);
        IconChoiceTypeExtension::decorate($view, ['s' => 'auto'], 'grid', 3);

        $this->assertCount(1, array_keys($view->vars['block_prefixes'], IconChoiceTypeExtension::BLOCK_PREFIX, true));
        $this->assertSame(3, $view->vars['cb_icon_columns']);
    }

    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addTypeExtension(new IconChoiceTypeExtension())
            ->getFormFactory();
    }
}
