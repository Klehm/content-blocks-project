<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Controller\Admin;

use ContentBlocks\Rendering\RenderMode;
use ContentBlocks\Sylius\Repository\ContentSlotRepository;
use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Slot\SlotType;
use Doctrine\Persistence\ObjectRepository;
use Sylius\Component\Channel\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/** Every channel's homepage, with whether builder content replaces it. */
final class HomepageController
{
    /** @param ObjectRepository<object> $channels */
    public function __construct(
        private readonly ObjectRepository $channels,
        private readonly ContentSlotRepository $slots,
        private readonly SlotRenderer $renderer,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(): Response
    {
        if (!$this->renderer->isEnabled(SlotType::HOMEPAGE)) {
            throw new NotFoundHttpException('The homepage slot is disabled.');
        }

        $rows = [];
        foreach ($this->channels->findBy([], ['code' => 'ASC']) as $channel) {
            if (!$channel instanceof ChannelInterface) {
                continue;
            }
            $slot = $this->slots->findOneByTypeAndSubject(SlotType::HOMEPAGE, (string) $channel->getCode());
            $rows[] = [
                'channel' => $channel,
                'slot' => $slot,
                'published' => $slot !== null && SlotRenderer::hasContent($slot->getContentArea(), RenderMode::PUBLIC),
            ];
        }

        return new Response($this->twig->render('@ContentBlocksSyliusPlugin/admin/page.html.twig', [
            'title' => 'content_blocks_sylius.ui.homepage',
            'hook' => 'content_blocks_sylius.admin.homepage',
            'context' => ['rows' => $rows],
        ]));
    }
}
