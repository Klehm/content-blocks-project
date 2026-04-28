<?php

declare(strict_types=1);

namespace App\Section;

use ContentBlocks\Entity\Section;
use ContentBlocks\Section\SectionDecoration;
use ContentBlocks\Section\SectionDecoratorInterface;

/**
 * Sandbox example: reads a free-form `backgroundColor` setting (added by
 * the matching FormTypeExtension) and projects it onto the section's
 * outer markup as an inline `background-color` style.
 *
 * Auto-tagged via instanceof in the package's services.php — host apps
 * with autoconfigure: true on src/* don't need any extra wiring.
 */
final class BackgroundColorSectionDecorator implements SectionDecoratorInterface
{
    public function decorate(array $settings, Section $section): SectionDecoration
    {
        $color = $settings['backgroundColor'] ?? null;

        if (!\is_string($color) || $color === '') {
            return new SectionDecoration();
        }

        return new SectionDecoration(inlineStyles: ['background-color' => $color]);
    }
}
