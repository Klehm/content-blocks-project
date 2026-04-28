<?php

declare(strict_types=1);

namespace App\Section;

use ContentBlocks\Section\SectionSettingsDefaultsProviderInterface;

/**
 * Sandbox example of a {@see SectionSettingsDefaultsProviderInterface}.
 *
 * The form opens with `backgroundColor` pre-set to white so the
 * ColorType picker shows white instead of the browser-default black.
 * The framework strips default-equal entries before passing them to
 * decorators, so a section saved with white still produces no inline
 * style — the markup only carries the user's actual overrides.
 */
final class SandboxSectionSettingsDefaults implements SectionSettingsDefaultsProviderInterface
{
    public function getDefaults(): array
    {
        return [
            'backgroundColor' => '#ffffff',
        ];
    }
}
