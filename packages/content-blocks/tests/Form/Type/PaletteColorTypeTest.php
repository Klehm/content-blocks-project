<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Form\Type;

use ContentBlocks\Form\Type\PaletteColorType;
use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ConfigColorPaletteProvider;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

final class PaletteColorTypeTest extends TypeTestCase
{
    protected function getExtensions(): array
    {
        $registry = new ColorPaletteRegistry([
            new ConfigColorPaletteProvider([
                ['label' => 'Primary', 'color' => '#eb0540'],
                ['label' => 'White', 'color' => '#ffffff'],
            ]),
        ]);

        return [
            new PreloadedExtension([new PaletteColorType($registry)], []),
        ];
    }

    public function testEmptyValueMapsToNoneAndBack(): void
    {
        $form = $this->factory->create(PaletteColorType::class, '');

        $this->assertSame('', $form->get('palette')->getData());
        $this->assertNull($form->get('custom')->getData());

        $form->submit(['palette' => '', 'custom' => '']);
        $this->assertSame('', $form->getData());
    }

    public function testPaletteHexMapsToPaletteChoice(): void
    {
        $form = $this->factory->create(PaletteColorType::class, '#EB0540');

        // Case-insensitive palette membership, canonicalized to the declared
        // hex so the <option> actually selects.
        $this->assertSame('#eb0540', $form->get('palette')->getData());
        $this->assertNull($form->get('custom')->getData());
    }

    public function testUnknownHexMapsToCustom(): void
    {
        $form = $this->factory->create(PaletteColorType::class, '#123456');

        $this->assertSame('custom', $form->get('palette')->getData());
        $this->assertSame('#123456', $form->get('custom')->getData());
    }

    public function testSubmitPaletteChoiceStoresItsHex(): void
    {
        $form = $this->factory->create(PaletteColorType::class);
        $form->submit(['palette' => '#ffffff', 'custom' => '#000000']);

        // Custom picker value is ignored unless "Custom…" is selected.
        $this->assertSame('#ffffff', $form->getData());
    }

    public function testSubmitCustomStoresThePickedHex(): void
    {
        $form = $this->factory->create(PaletteColorType::class);
        $form->submit(['palette' => 'custom', 'custom' => '#0000ff']);

        $this->assertSame('#0000ff', $form->getData());
    }

    public function testSubmitCustomWithoutValueStoresEmpty(): void
    {
        $form = $this->factory->create(PaletteColorType::class);
        $form->submit(['palette' => 'custom', 'custom' => '']);

        $this->assertSame('', $form->getData());
    }

    public function testAllowCustomFalseDropsTheCustomChoice(): void
    {
        $form = $this->factory->create(PaletteColorType::class, null, ['allow_custom' => false]);
        $form->submit(['palette' => 'custom', 'custom' => '#0000ff']);

        // 'custom' is not a valid choice anymore → the palette child fails,
        // the mapper receives null and stores the empty state.
        $this->assertSame('', $form->getData());
    }

    public function testViewWiresTheConditionController(): void
    {
        $view = $this->factory->create(PaletteColorType::class)->createView();

        $this->assertSame('cb-condition', $view->vars['attr']['data-controller']);
        $this->assertSame(
            'palette:custom',
            $view['custom']->vars['row_attr']['data-cb-condition'],
        );
    }

    public function testChoiceLabelsTranslateInTheCoreDomain(): void
    {
        // Regression: the None / Custom… choice labels are translation keys. A
        // child select does not inherit translation_domain when ChoiceType
        // resolves choice_translation_domain, so without an explicit domain it
        // falls back to null → labels look up in `messages`, miss, and render as
        // the raw keys (cb.styling.palette.none / .custom).
        $view = $this->factory->create(PaletteColorType::class)->createView();

        $this->assertSame('content_blocks', $view['palette']->vars['choice_translation_domain']);
    }

    public function testChoiceDomainStaysCoreEvenWhenFieldDomainIsOverridden(): void
    {
        // Kit blocks add PaletteColorType with their own catalog, e.g.
        // DividerBlock / IconBlock pass 'content_blocks_kit'. The None / Custom…
        // keys live only in the core catalog, so the choice domain must stay
        // 'content_blocks' regardless of the field's translation_domain — else
        // the options render as raw keys inside kit block forms.
        $view = $this->factory
            ->create(PaletteColorType::class, null, ['translation_domain' => 'content_blocks_kit'])
            ->createView();

        $this->assertSame('content_blocks', $view['palette']->vars['choice_translation_domain']);
    }

    public function testPaletteOptionsCarryTheirHexAsDataAttribute(): void
    {
        $view = $this->factory->create(PaletteColorType::class)->createView();

        $byValue = [];
        foreach ($view['palette']->vars['choices'] as $choice) {
            $byValue[$choice->value] = $choice->attr;
        }

        $this->assertSame(['data-color' => '#eb0540'], $byValue['#eb0540']);
        $this->assertSame([], $byValue['']);
        $this->assertSame([], $byValue['custom']);
    }
}
