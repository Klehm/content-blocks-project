<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Asset\AssetGarbageCollector;
use ContentBlocks\Asset\AssetReportViewerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Read-only view of what the sweep would reclaim. No delete button, ever, and
 * a 404 rather than a 403 until a host opts in.
 *
 * @see docs/internals/assets.md#why-the-sweep-is-a-command
 *
 * @internal The route is the contract, not this class. See FREEZE-AUDIT.md.
 */
#[Route('/_content-blocks')]
final class AssetReportController
{
    public function __construct(
        private readonly AssetGarbageCollector $collector,
        private readonly AssetReportViewerInterface $viewer,
        private readonly Environment $twig,
    ) {
    }

    #[Route('/assets/report', name: 'content_blocks_asset_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        if (!$this->viewer->canViewAssetReport()) {
            throw new NotFoundHttpException();
        }

        $retention = filter_var(
            $request->query->get('retention', (string) AssetGarbageCollector::DEFAULT_RETENTION_DAYS),
            \FILTER_VALIDATE_INT,
        );
        if ($retention === false || $retention < 0) {
            $retention = AssetGarbageCollector::DEFAULT_RETENTION_DAYS;
        }

        if (!$this->collector->isSupported()) {
            return new Response(
                $this->twig->render('@ContentBlocks/assets/report.html.twig', [
                    'supported' => false,
                    'report' => null,
                    'retention' => $retention,
                ]),
            );
        }

        $report = $this->collector->collect(dryRun: true, retentionDays: $retention);

        if ($request->query->get('format') === 'json') {
            return new JsonResponse($report->toArray());
        }

        return new Response(
            $this->twig->render('@ContentBlocks/assets/report.html.twig', [
                'supported' => true,
                'report' => $report,
                'retention' => $retention,
            ]),
        );
    }
}
