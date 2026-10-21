<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use ContentBlocks\Kit\Form\Type\RichTextEditorType;
use ContentBlocks\Schema\FieldSchemaContributorInterface;
use ContentBlocks\Schema\FormSchemaMapper;
use Symfony\Component\Form\FormInterface;

/**
 * A rich-text field holds HTML, which a plain string would not tell a model.
 * Whatever it writes is sanitized again on render.
 */
final class RichTextFieldSchema implements FieldSchemaContributorInterface
{
    public function schemaFor(FormInterface $field): ?array
    {
        if (!FormSchemaMapper::is($field, RichTextEditorType::class)) {
            return null;
        }

        return [
            'type' => 'string',
            'contentMediaType' => 'text/html',
            'description' => 'HTML body: <p>, <h2>-<h4>, <strong>, <em>, <a href>, <ul>/<ol>/<li>. '
                . 'No <script>, no <style>, no inline event handlers.',
        ];
    }
}
