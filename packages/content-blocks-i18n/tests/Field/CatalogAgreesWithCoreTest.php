<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Tests\Field;

use ContentBlocks\I18n\Field\TranslatableField;
use ContentBlocks\I18n\Tests\Fixtures\CatalogFactory;
use PHPUnit\Framework\TestCase;

/**
 * The join this package's metadata walk makes against the core's tag reader.
 *
 * Two walkers over the same form tree is the design's one real duplication
 * risk: the core decides *which* fields are translatable (the frozen
 * `cb_translatable` convention), this package decides *how they look*, and they
 * meet on path patterns. If the two ever produce different patterns, fields
 * silently lose their labels — or worse, a field the core reports as
 * translatable never appears in the workbench at all. This test is what keeps
 * them honest.
 */
final class CatalogAgreesWithCoreTest extends TestCase
{
    public function testEveryPatternTheCoreReportsIsResolvedByTheCatalog(): void
    {
        $data = [
            'heading' => 'Welcome',
            'body' => 'Body copy',
            'align' => 'center',
            'items' => [['_id' => 'aa11', 'label' => 'Label', 'url' => '/u', 'src' => '']],
        ];

        $corePatterns = CatalogFactory::translatableFields()->forBlockType('fixture', $data);
        $fields = CatalogFactory::create()->build('fixture', $data);

        $catalogPatterns = array_values(array_unique(
            array_map(static fn (TranslatableField $f): string => $f->pattern, $fields),
        ));

        sort($corePatterns);
        sort($catalogPatterns);

        $this->assertSame($corePatterns, $catalogPatterns);
    }

    public function testEveryListedFieldCarriesARealLabelRatherThanAFallback(): void
    {
        // A missing label means the metadata walk did not reach the field —
        // the exact drift this test exists to catch.
        $data = [
            'heading' => 'Welcome',
            'body' => 'Body copy',
            'align' => 'center',
            'items' => [['_id' => 'aa11', 'label' => 'Label', 'url' => '/u', 'src' => '']],
        ];

        foreach (CatalogFactory::create()->build('fixture', $data) as $field) {
            $this->assertNotSame('', $field->label, $field->path);
            $this->assertMatchesRegularExpression('/^[A-Z]/', $field->label, $field->path);
        }
    }
}
