<?php

declare(strict_types=1);

namespace ContentBlocks\Schema;

use ContentBlocks\Form\Type\ImageUploadType;
use ContentBlocks\Form\Type\VideoUploadType;
use Symfony\Component\Form\FormInterface;

/**
 * An upload field stores the path of a file already uploaded. A model cannot
 * produce one, so the schema says to reuse a known path or leave it empty.
 */
final class UploadFieldSchema implements FieldSchemaContributorInterface
{
    public function schemaFor(FormInterface $field): ?array
    {
        $kind = match (true) {
            // Video first: it is built on the image type.
            FormSchemaMapper::is($field, VideoUploadType::class) => 'video',
            FormSchemaMapper::is($field, ImageUploadType::class) => 'image',
            default => null,
        };
        if ($kind === null) {
            return null;
        }

        return [
            'type' => 'string',
            'description' => sprintf(
                'Path or URL of an uploaded %s, empty for none. Reuse one you were given; never invent one.',
                $kind,
            ),
        ];
    }
}
