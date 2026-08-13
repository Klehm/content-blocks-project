<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Tests\Block;

use ContentBlocks\Form\Extension\TranslatableFieldTypeExtension;
use ContentBlocks\Kit\Block\AlertBlock;
use ContentBlocks\Kit\Block\ButtonBlock;
use ContentBlocks\Kit\Twig\ChoiceTokenExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * `content_blocks_kit.blocks.<type>.choices` in its two shapes.
 *
 * A **list** is an allow-list over the coded set — what the option has always
 * been, kept working. A **value:label map** replaces the set, which is what lets
 * a host add a value the kit never coded. The distinction is made on the shape
 * of the value alone, so nothing had to be flagged in config.
 *
 * These tests follow one added value all the way through: into the picker, past
 * the validator, into the block's initial data, and out into the rendered page —
 * because it is only useful if it survives every one of those.
 */
final class ChoiceOverrideTest extends TestCase
{
    // ---------- the picker ----------

    public function testAListStillRestrictsAndReordersTheCodedSet(): void
    {
        $block = new ButtonBlock([], ['variant' => ['outline', 'primary']], []);

        $this->assertSame(['outline', 'primary'], $this->values($block, 'variant'));
    }

    public function testAListIgnoresValuesTheBlockDoesNotCode(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost', 'primary']], []);

        $this->assertSame(['primary'], $this->values($block, 'variant'));
    }

    public function testAnAllInvalidListFallsBackToTheFullCodedSet(): void
    {
        // Kept from the previous behaviour: an empty select is worse than an
        // unfiltered one. It is also why a host trying to *add* through the
        // list form saw nothing happen — hence the map form below.
        $block = new ButtonBlock([], ['variant' => ['ghost']], []);

        $this->assertSame(['primary', 'secondary', 'outline', 'link'], $this->values($block, 'variant'));
    }

    public function testAMapReplacesTheSetAndCanAddValues(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost', 'primary' => 'Primary']], []);

        $this->assertSame(['ghost', 'primary'], $this->values($block, 'variant'));
    }

    public function testAMapKeepsItsOwnOrder(): void
    {
        $block = new ButtonBlock([], ['variant' => ['link' => 'L', 'ghost' => 'G', 'primary' => 'P']], []);

        $this->assertSame(['link', 'ghost', 'primary'], $this->values($block, 'variant'));
    }

    public function testNumericMapKeysBecomeStringValues(): void
    {
        // YAML hands over `2: 'Two'` as an int key; ChoiceType stores whatever
        // it is given, and Block.data is JSON — so pin the cast.
        $block = new ButtonBlock([], ['variant' => [2 => 'Two']], []);

        $this->assertSame(['2'], $this->values($block, 'variant'));
    }

    public function testTwoValuesSharingALabelBothSurvive(): void
    {
        // ChoiceType keys its array by label, so a naive flip would drop one.
        $block = new ButtonBlock([], ['variant' => ['a' => 'Same', 'b' => 'Same']], []);

        $this->assertSame(['a', 'b'], $this->values($block, 'variant'));
    }

    // ---------- the labels ----------

    public function testACodedLabelIsTranslated(): void
    {
        $html = $this->renderVariantSelect(new ButtonBlock());

        $this->assertStringContainsString('<option value="primary">Primaire</option>', $html);
    }

    public function testAConfiguredTranslationKeyIsTranslated(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'app.btn.ghost']], []);

        $this->assertStringContainsString(
            '<option value="ghost">Fantôme</option>',
            $this->renderVariantSelect($block),
        );
    }

    public function testAConfiguredPlainLabelIsRenderedAsWritten(): void
    {
        // Symfony returns an unknown key unchanged, so a host that does not want
        // to add a catalogue entry can write the label inline and it survives
        // the |trans the form theme applies to every choice label.
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost']], []);

        $this->assertStringContainsString(
            '<option value="ghost">Ghost</option>',
            $this->renderVariantSelect($block),
        );
    }

    // ---------- the validator ----------

    public function testTheConstraintAcceptsAValueAddedByConfig(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost']], []);

        $this->assertContains('ghost', $this->constraintChoices($block, 'variant'));
    }

    public function testTheConstraintStillAcceptsACodedValueTheConfigHid(): void
    {
        // Content saved before the override must not become invalid: narrowing
        // the picker is a UI decision, not a data migration.
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost']], []);

        $this->assertContains('outline', $this->constraintChoices($block, 'variant'));
    }

    // ---------- the initial data ----------

    public function testTheDefaultMovesToAnOfferedValueWhenConfigDropsIt(): void
    {
        // Without this, every new button would start on `primary` — a value the
        // host's config just removed, missing from the dropdown and unstyled.
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost', 'flat' => 'Flat']], []);

        $this->assertSame('ghost', $block->getDefaultData()['variant']);
    }

    public function testTheDefaultIsLeftAloneWhenItIsStillOffered(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost', 'primary' => 'Primary']], []);

        $this->assertSame('primary', $block->getDefaultData()['variant']);
    }

    public function testAnExplicitDefaultOverrideStillWins(): void
    {
        $block = new ButtonBlock([], ['variant' => ['ghost' => 'Ghost', 'flat' => 'Flat']], ['variant' => 'flat']);

        $this->assertSame('flat', $block->getDefaultData()['variant']);
    }

    public function testUnconfiguredBlocksKeepTheirCodedDefaults(): void
    {
        $this->assertSame('primary', (new ButtonBlock())->getDefaultData()['variant']);
        $this->assertSame('info', (new AlertBlock())->getDefaultData()['type']);
    }

    // ---------- the page ----------

    public function testAnAddedVariantReachesTheRenderedClass(): void
    {
        // The end of the chain, and the half that used to be missing: the view
        // no longer re-lists the coded values, so the stored value is what gets
        // rendered and the host's CSS has something to hook onto.
        $html = $this->renderButton(['text' => 'Go', 'variant' => 'ghost', 'size' => 'md', 'align' => 'start']);

        $this->assertStringContainsString('cb-kit-btn--ghost', $html);
        $this->assertStringNotContainsString('cb-kit-btn--primary', $html);
    }

    public function testAnAddedAlertTypeRendersWithTheFallbackGlyph(): void
    {
        // The type styles the alert (class suffix, passes through) but the glyph
        // map only knows the kit's own icons — so an added type gets `info`'s
        // glyph rather than a null handed to cb_kit_icon().
        $html = $this->renderTemplate('@ContentBlocksKit/block/alert/view.html.twig', [
            'content' => 'Heads up',
            'type' => 'tip',
        ]);

        $this->assertStringContainsString('cb-kit-alert--tip', $html);
        $this->assertStringContainsString('<svg', $html);
    }

    // ---------- helpers ----------

    /**
     * @param array<string, mixed> $data
     */
    private function renderButton(array $data): string
    {
        return $this->renderTemplate('@ContentBlocksKit/block/button/view.html.twig', $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderTemplate(string $template, array $data): string
    {
        $kitRoot = \dirname(__DIR__, 2);
        $loader = new FilesystemLoader();
        $loader->addPath($kitRoot . '/templates', 'ContentBlocksKit');

        $env = new Environment($loader, ['strict_variables' => false]);
        $env->addExtension(new ChoiceTokenExtension());
        $env->addExtension(new TranslationExtension($this->translator()));
        $env->addExtension(new \ContentBlocks\Kit\Twig\IconExtension(new \ContentBlocks\Kit\Icon\IconSet()));

        return $env->render($template, ['data' => $data]);
    }

    /**
     * @return list<string>
     */
    private function values(ButtonBlock $block, string $field): array
    {
        $method = new \ReflectionMethod($block, 'choices');

        return array_values($method->invoke($block, $field));
    }

    /**
     * @return list<string>
     */
    private function constraintChoices(ButtonBlock $block, string $field): array
    {
        $method = new \ReflectionMethod($block, 'choiceConstraint');

        return $method->invoke($block, $field)->choices;
    }

    private function renderVariantSelect(ButtonBlock $block): string
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addTypeExtension(new TranslatableFieldTypeExtension())
            ->getFormFactory();

        $builder = $factory->createBuilder(FormType::class, null, ['data_class' => null]);
        $block->buildForm($builder, $block->getDefaultData());
        $form = $builder->getForm();

        $vendor = \dirname(__DIR__, 2) . '/vendor';
        $loader = new FilesystemLoader();
        $loader->addPath($vendor . '/symfony/twig-bridge/Resources/views/Form');

        $twig = new Environment($loader);
        $twig->addExtension(new TranslationExtension($this->translator()));
        $engine = new TwigRendererEngine(['form_div_layout.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            FormRenderer::class => static fn (): FormRenderer => new FormRenderer($engine),
        ]));
        $twig->addExtension(new FormExtension());

        return $twig->createTemplate('{{ form_widget(form.variant) }}')
            ->render(['form' => $form->createView()]);
    }

    private function translator(): Translator
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'cb_kit.block.button.variant.primary' => 'Primaire',
            'app.btn.ghost' => 'Fantôme',
        ], 'fr', 'content_blocks_kit');

        return $translator;
    }
}
