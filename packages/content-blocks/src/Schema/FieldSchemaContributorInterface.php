<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use Symfony\Component\Form\FormInterface;

/**
 * The JSON Schema of a form field whose stored value the mapper cannot read
 * off its children, such as a type with its own data mapper.
 *
 * @see docs/guide/content-schema.md#a-field-type-of-your-own
 */
interface FieldSchemaContributorInterface
{
    /**
     * @return array<string, mixed>|null the field's schema, or null to leave
     *                                   it to the next contributor
     */
    public function schemaFor(FormInterface $field): ?array;
}
