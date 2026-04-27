<?php

declare(strict_types=1);

namespace ContentBlocks\Preview;

use ContentBlocks\Entity\ContentArea;

/**
 * Resolves the public URL of the page (or whatever resource) that owns a
 * given ContentArea. The host app must implement this interface — there is
 * no convention this package can derive from a ContentArea alone.
 *
 * The returned URL is the *clean* public URL, without any preview-mode
 * query parameter. The `cb_preview_url(area)` Twig function appends
 * `?cb_preview=1` to that URL when wiring the iframe in the builder shell.
 *
 * Example (host app):
 *
 *     final class PageContentAreaUrlResolver implements ContentAreaUrlResolverInterface
 *     {
 *         public function __construct(
 *             private readonly EntityManagerInterface $em,
 *             private readonly UrlGeneratorInterface $urls,
 *         ) {}
 *
 *         public function resolve(ContentArea $area): string
 *         {
 *             $page = $this->em->getRepository(Page::class)->findOneBy(['contentArea' => $area]);
 *             if (!$page) throw new \RuntimeException('No page for area ' . $area->getId());
 *             return $this->urls->generate('app_page_show', ['id' => $page->getId()]);
 *         }
 *     }
 */
interface ContentAreaUrlResolverInterface
{
    public function resolve(ContentArea $area): string;
}
