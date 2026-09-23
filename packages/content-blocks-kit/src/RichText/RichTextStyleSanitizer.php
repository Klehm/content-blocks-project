<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\RichText;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps the inline declarations the editors write (colour, alignment, sizes)
 * and drops the rest: no positioning, no `url()`, no escapes.
 */
final class RichTextStyleSanitizer implements AttributeSanitizerInterface
{
    private const PROPERTIES = [
        'color', 'background-color', 'text-align', 'text-decoration',
        'font-weight', 'font-style', 'font-size', 'font-family',
        'line-height', 'letter-spacing', 'vertical-align', 'list-style-type',
        'width', 'height', 'max-width', 'float', 'display',
        'margin', 'margin-left', 'margin-right', 'margin-top', 'margin-bottom',
        'padding', 'padding-left', 'padding-right', 'padding-top',
        'padding-bottom', 'border', 'border-width', 'border-style',
        'border-color', 'border-collapse', 'border-spacing',
    ];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(
        string $element,
        string $attribute,
        string $value,
        HtmlSanitizerConfig $config,
    ): ?string {
        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            [$property, $propertyValue] = array_map(trim(...), explode(':', $declaration, 2)) + [1 => ''];
            $property = strtolower($property);
            if (!\in_array($property, self::PROPERTIES, true) || $propertyValue === '') {
                continue;
            }
            if (preg_match('/[\\\\<>"\'()]|expression|url|@import/i', $propertyValue) === 1) {
                // rgb()/rgba() is the one bracketed value editors write.
                if (preg_match('/^rgba?\(\s*[\d.\s,%]+\)$/i', $propertyValue) !== 1) {
                    continue;
                }
            }
            $kept[] = $property . ': ' . $propertyValue;
        }

        return $kept === [] ? null : implode('; ', $kept);
    }
}
