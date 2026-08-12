<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Field;

use ContentBlocks\I18n\Field\FieldStatus;
use ContentBlocks\I18n\Field\SourceDigest;
use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use PHPUnit\Framework\TestCase;

/**
 * The status rules — the part of this package an editorial team argues with
 * most, so each one is pinned separately.
 */
final class TranslatableFieldCatalogTest extends TestCase
{
    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'heading' => 'Welcome',
            'body' => 'We ship worldwide.',
            'align' => 'center',
            'items' => [
                ['_id' => 'aa11', 'label' => 'Fast delivery', 'url' => '/delivery', 'src' => '/img/a.png'],
            ],
        ];
    }

    /**
     * @param list<TranslatableField> $fields
     *
     * @return array<string, TranslatableField>
     */
    private function byPath(array $fields): array
    {
        $out = [];

        foreach ($fields as $field) {
            $out[$field->path] = $field;
        }

        return $out;
    }

    public function testOnlyTaggedFieldsAreListed(): void
    {
        $fields = $this->byPath(CatalogFactory::create()->build('fixture', $this->source()));

        $this->assertArrayHasKey('heading', $fields);
        $this->assertArrayHasKey('body', $fields);
        $this->assertArrayHasKey('items[aa11].label', $fields);
        $this->assertArrayHasKey('items[aa11].url', $fields);

        // An enum reads the same in every language; an uploaded asset is an
        // asset-management decision, not a translation.
        $this->assertArrayNotHasKey('align', $fields);
        $this->assertArrayNotHasKey('items[aa11].src', $fields);
    }

    public function testAFieldWithNoStoredValueIsMissing(): void
    {
        $fields = $this->byPath(CatalogFactory::create()->build('fixture', $this->source()));

        $this->assertSame(FieldStatus::MISSING, $fields['heading']->status);
        $this->assertNull($fields['heading']->value);
        $this->assertSame('Welcome', $fields['heading']->source);
    }

    public function testAValueWhoseDigestStillMatchesIsTranslated(): void
    {
        $fields = $this->byPath(CatalogFactory::create()->build(
            'fixture',
            $this->source(),
            ['heading' => 'Bienvenue'],
            ['heading' => SourceDigest::of('Welcome')],
        ));

        $this->assertSame(FieldStatus::TRANSLATED, $fields['heading']->status);
        $this->assertSame('Bienvenue', $fields['heading']->value);
    }

    public function testAValueWhoseSourceChangedIsOutdated(): void
    {
        // The French was written against "Welcome to our shop"; the English has
        // since been shortened. The page still renders the French — it just
        // describes something else now, which is the whole reason this state
        // is tracked separately from "translated".
        $fields = $this->byPath(CatalogFactory::create()->build(
            'fixture',
            $this->source(),
            ['heading' => 'Bienvenue dans notre boutique'],
            ['heading' => SourceDigest::of('Welcome to our shop')],
        ));

        $this->assertSame(FieldStatus::OUTDATED, $fields['heading']->status);
        $this->assertSame('Bienvenue dans notre boutique', $fields['heading']->value);
    }

    public function testAValueStoredWithoutADigestIsNotReportedStale(): void
    {
        // Rows written before digests existed, or by an import. Flagging them
        // would fill the workbench with alarms nobody can act on.
        $fields = $this->byPath(CatalogFactory::create()->build(
            'fixture',
            $this->source(),
            ['heading' => 'Bienvenue'],
            [],
        ));

        $this->assertSame(FieldStatus::TRANSLATED, $fields['heading']->status);
    }

    public function testADeliberateBlankIsATranslationNotAGap(): void
    {
        // An optional subtitle that exists in English and shouldn't in German.
        // Reading `''` as missing would fall back and print the English.
        $fields = $this->byPath(CatalogFactory::create()->build(
            'fixture',
            $this->source(),
            ['body' => ''],
            ['body' => SourceDigest::of('We ship worldwide.')],
        ));

        $this->assertSame(FieldStatus::TRANSLATED, $fields['body']->status);
        $this->assertSame('', $fields['body']->value);
    }

    public function testFieldsWithABlankSourceAreNotListed(): void
    {
        // There is nothing to translate, so counting it would park every page
        // short of 100% for a reason no editor can act on.
        $source = $this->source();
        $source['body'] = '   ';

        $fields = $this->byPath(CatalogFactory::create()->build('fixture', $source));

        $this->assertArrayNotHasKey('body', $fields);
        $this->assertArrayHasKey('heading', $fields);
    }

    public function testCollectionFieldsCarryTheirEntryNumberForLabelling(): void
    {
        $source = $this->source();
        $source['items'][] = ['_id' => 'bb22', 'label' => 'Free returns', 'url' => '/returns', 'src' => ''];

        $fields = $this->byPath(CatalogFactory::create()->build('fixture', $source));

        $this->assertSame(1, $fields['items[aa11].label']->entryIndex);
        $this->assertSame(2, $fields['items[bb22].label']->entryIndex);
    }

    public function testWidgetsAreDerivedFromTheFormType(): void
    {
        // The workbench renders by widget, and a machine translator needs to
        // know whether it is handling markup.
        $fields = $this->byPath(CatalogFactory::create()->build('fixture', $this->source()));

        $this->assertSame('text', $fields['heading']->widget);
        $this->assertSame('textarea', $fields['body']->widget);
        $this->assertSame('url', $fields['items[aa11].url']->widget);
    }

    public function testAnUnregisteredBlockTypeYieldsNothing(): void
    {
        $this->assertSame([], CatalogFactory::create()->build('nope', $this->source()));
    }
}
