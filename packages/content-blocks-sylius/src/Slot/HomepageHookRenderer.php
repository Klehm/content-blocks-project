<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Slot;

use ContentBlocks\Rendering\BlockRendererInterface;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Channel\Context\ChannelNotFoundException;
use Sylius\TwigHooks\Hook\Renderer\HookRendererInterface;

/**
 * The channel's homepage content takes the place of the homepage hook, so the
 * native sections go as soon as there is something to show instead.
 *
 * @see docs/internals/sylius.md#the-homepage-replaces-a-hook
 */
final class HomepageHookRenderer implements HookRendererInterface
{
    public const HOOK = 'sylius_shop.homepage.index';

    public function __construct(
        private readonly HookRendererInterface $inner,
        private readonly SlotRenderer $slots,
        private readonly ContentSlotRepository $repository,
        private readonly ChannelContextInterface $channels,
        private readonly BlockRendererInterface $renderer,
    ) {
    }

    public function render(array $hookNames, array $hookContext = []): string
    {
        if (!\in_array(self::HOOK, $hookNames, true) || !$this->slots->isEnabled(SlotType::HOMEPAGE)) {
            return $this->inner->render($hookNames, $hookContext);
        }

        try {
            $code = (string) $this->channels->getChannel()->getCode();
        } catch (ChannelNotFoundException) {
            return $this->inner->render($hookNames, $hookContext);
        }

        $slot = $this->repository->findOneByTypeAndSubject(SlotType::HOMEPAGE, $code);
        if ($slot === null) {
            return $this->inner->render($hookNames, $hookContext);
        }

        $area = $slot->getContentArea();
        $mode = $this->renderer->resolveMode($area);
        $content = $this->slots->render(SlotType::HOMEPAGE, $code) ?? '';

        // An empty draft keeps the native sections below the builder's chrome.
        return SlotRenderer::hasContent($area, $mode)
            ? $content
            : $content . $this->inner->render($hookNames, $hookContext);
    }
}
