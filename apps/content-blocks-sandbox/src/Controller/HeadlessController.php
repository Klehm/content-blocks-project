<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Page;
use ContentBlocks\Headless\ContentAreaNormalizerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A page's content as JSON, the runnable demo of docs/guide/headless.md.
 *
 * The route is the host's, not the package's: only the host knows whether a
 * page may be read at all (here every page is public). `?cb_preview=1` gives
 * an editor the draft, by the same rule as the HTML page; `{_locale}` the
 * translation, by routing alone.
 */
final class HeadlessController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContentAreaNormalizerInterface $normalizer,
    ) {
    }

    #[Route('/api/page/{id}', name: 'app_page_api', requirements: ['id' => '\d+'])]
    #[Route(
        '/{_locale}/api/page/{id}',
        name: 'app_page_api_localized',
        requirements: ['id' => '\d+', '_locale' => 'en|de|es'],
    )]
    public function page(int $id): JsonResponse
    {
        $area = $this->em->find(Page::class, $id)?->getContentArea();

        if ($area === null) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        return JsonResponse::fromJsonString($this->normalizer->toJson($area));
    }
}
