<?php

declare(strict_types=1);

namespace App\ContentBlocks\Block;

use ContentBlocks\BlockType\AsContentBlock;
use ContentBlocks\Kit\Block\DividerBlock as KitDividerBlock;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

/**
 * The documented way to extend a kit block: keep `getType()`, switch the kit's
 * own service off (`content_blocks_kit.blocks.divider.enabled: false`, since two
 * services cannot claim one type id), and add what the kit does not offer.
 *
 * It stands here as the fixture for a bug that had no other way of being
 * caught: `content_blocks_kit.blocks.divider` used to be dropped in silence for
 * a subclass, because the bundle only wired its config onto the services it
 * registered itself — and subclassing is precisely what un-registers them. So
 * this class carries **no** choices or defaults of its own: what the picker
 * offers here comes entirely from the host's YAML, through a subclass.
 */
#[AsContentBlock(priority: 45)]
final class DividerBlock extends KitDividerBlock
{
    public function buildForm(FormBuilderInterface $builder, array $data): void
    {
        parent::buildForm($builder, $data);

        // One field the kit does not have — the reason to subclass at all,
        // rather than to configure.
        $builder->add('printOnly', CheckboxType::class, [
            'label' => 'Masquer à l\'écran (impression seule)',
            'required' => false,
            'data' => $data['printOnly'] ?? false,
        ]);
    }

    protected function defaults(): array
    {
        return parent::defaults() + ['printOnly' => false];
    }
}
