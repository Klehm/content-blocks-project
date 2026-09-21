<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Extension;

use ContentBlocks\Form\Extension\SidebarLayoutTypeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SidebarLayoutTypeExtensionTest extends TestCase
{
    public function testTheOptionsReachTheView(): void
    {
        $view = $this->factory()->createBuilder(FormType::class)
            ->add('seoTitle', TextType::class, [
                'cb_group' => 'SEO',
                'cb_panel' => 'Search result',
                'cb_help_tooltip' => 'Shown by search engines.',
            ])
            ->getForm()
            ->createView();

        $this->assertSame('SEO', $view['seoTitle']->vars['cb_group']);
        $this->assertSame('Search result', $view['seoTitle']->vars['cb_panel']);
        $this->assertSame('Shown by search engines.', $view['seoTitle']->vars['cb_help_tooltip']);
        $this->assertTrue($view->vars['cb_panels_exclusive']);
    }

    public function testAFieldWithoutThemIsUngrouped(): void
    {
        $view = $this->factory()->createBuilder(FormType::class)
            ->add('title', TextType::class)
            ->getForm()
            ->createView();

        $this->assertNull($view['title']->vars['cb_group']);
        $this->assertNull($view['title']->vars['cb_panel']);
        $this->assertNull($view['title']->vars['cb_help_tooltip']);
    }

    public function testTheOlderDataCbGroupAttributeStillPicksTheTab(): void
    {
        $view = $this->factory()->createBuilder(FormType::class)
            ->add('seoTitle', TextType::class, ['attr' => ['data-cb-group' => 'SEO']])
            ->add('both', TextType::class, [
                'attr' => ['data-cb-group' => 'Old'],
                'cb_group' => 'New',
            ])
            ->getForm()
            ->createView();

        $this->assertSame('SEO', $view['seoTitle']->vars['cb_group']);
        $this->assertSame('New', $view['both']->vars['cb_group'], 'the option wins');
    }

    public function testATranslatableLabelIsAccepted(): void
    {
        $label = new class () implements TranslatableInterface {
            public function trans(TranslatorInterface $translator, ?string $locale = null): string
            {
                return $translator->trans('app.tab.seo', [], 'admin', $locale);
            }
        };
        $view = $this->factory()->createBuilder(FormType::class)
            ->add('seoTitle', TextType::class, ['cb_group' => $label, 'cb_panel' => $label])
            ->getForm()
            ->createView();

        $this->assertSame($label, $view['seoTitle']->vars['cb_group']);
    }

    public function testPanelsCanBeMadeIndependent(): void
    {
        $view = $this->factory()->create(FormType::class, null, ['cb_panels_exclusive' => false])->createView();

        $this->assertFalse($view->vars['cb_panels_exclusive']);
    }

    public function testAWrongTypeIsRefused(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->factory()->create(TextType::class, null, ['cb_panel' => ['not', 'a', 'label']]);
    }

    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addTypeExtension(new SidebarLayoutTypeExtension())
            ->getFormFactory();
    }
}
