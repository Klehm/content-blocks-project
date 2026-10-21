<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Slot;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Rendering\RenderContext;
use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;

/**
 * Renders a native page's slot: nothing at all while it has nothing to show,
 * so the page looks as Sylius made it; the builder's chrome in preview.
 *
 * @see docs/internals/sylius.md#native-pages
 */
final class SlotRenderer
{
    /** @param list<string> $enabledTypes */
    public function __construct(
        private readonly ContentSlotRepository $slots,
        private readonly BlockRendererInterface $renderer,
        private readonly array $enabledTypes,
    ) {
    }

    public function isEnabled(string $type): bool
    {
        return \in_array($type, $this->enabledTypes, true);
    }

    /** Null when the slot does not exist or shows nothing in this request. */
    public function render(string $type, string $subject): ?string
    {
        if (!$this->isEnabled($type)) {
            return null;
        }
        $slot = $this->slots->findOneByTypeAndSubject($type, $subject);
        if ($slot === null) {
            return null;
        }

        $area = $slot->getContentArea();
        $mode = $this->renderer->resolveMode($area);
        if ($mode === RenderMode::PUBLIC && !self::hasContent($area, $mode)) {
            return null;
        }

        return $this->renderer->render($area, (new RenderContext())->withMode($mode));
    }

    /**
     * Whether the area has a section to show: a published one to a visitor,
     * one not deleted from the draft in preview.
     */
    public static function hasContent(ContentArea $area, RenderMode $mode): bool
    {
        foreach ($area->getSections() as $section) {
            if ($mode === RenderMode::PUBLIC ? $section->isPublished() : !$section->isDeleted()) {
                return true;
            }
        }

        return false;
    }
}
