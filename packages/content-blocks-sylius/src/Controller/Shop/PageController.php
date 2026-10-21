<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Controller\Shop;

use ContentBlocks\Sylius\Repository\ContentPageRepository;
use ContentBlocks\Sylius\Security\AdminVisitor;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

/** A shop page: enabled and on this channel, or previewed by an admin. */
final class PageController
{
    public function __construct(
        private readonly ContentPageRepository $pages,
        private readonly ChannelContextInterface $channels,
        private readonly LocaleContextInterface $locale,
        private readonly AdminVisitor $admin,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(string $slug): Response
    {
        $page = $this->pages->findOneByChannelAndSlug(
            $this->channels->getChannel(),
            $this->locale->getLocaleCode(),
            $slug,
        );
        if ($page === null || (!$page->isEnabled() && !$this->admin->isAdmin())) {
            throw new NotFoundHttpException(sprintf('No page "%s" here.', $slug));
        }

        return new Response($this->twig->render('@ContentBlocksSyliusPlugin/shop/page/show.html.twig', [
            'page' => $page,
        ]));
    }
}
