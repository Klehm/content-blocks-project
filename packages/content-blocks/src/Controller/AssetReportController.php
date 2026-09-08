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
 * A read-only view of what the sweep would reclaim, for operators who do not
 * live in a terminal.
 *
 * **Read-only on purpose.** There is no delete button and there will not be
 * one: deletion is irreversible, and reference detection is a heuristic over
 * host-shaped JSON. Seeing the list in a browser and acting on it are two
 * different levels of deliberateness, and the second one belongs to
 * `content-blocks:assets:gc --force`, where an operator has a shell, a backup
 * and an audit trail. The page says so, and prints the command.
 *
 * Denied by default: the report spans every area in the install, so it is
 * gated by its own capability (see {@see AssetReportViewerInterface}) and 404s
 * — not 403 — until a host opts in, so an install that never wired it does not
 * advertise the route's existence.
 *
 * Cost: one full directory walk plus one pass over the content tables per
 * view. That is the same work the command does, and the reason this is an
 * operator page rather than something linked from the builder chrome.
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
