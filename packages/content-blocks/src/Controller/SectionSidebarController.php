<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Security\AccessCheckerInterface;
use ContentBlocks\Security\ContentBlocksAccessDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Renders + handles the section settings form in the builder sidebar.
 *
 * GET  → rendered HTML form (mounted by cb-builder into <aside>).
 * POST → submits form data; on success the section's draft_settings is
 *        updated and the response is empty 204 (the cb-section-settings-form
 *        Stimulus controller fires cb:section:saved on 2xx so the parent
 *        unmounts the sidebar + reloads the iframe). On validation error,
 *        re-renders the form HTML with errors and returns 422 so the
 *        Stimulus controller swaps the sidebar content.
 */
#[Route('/_content-blocks')]
final class SectionSidebarController
{
    use CsrfProtectedTrait;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessCheckerInterface $accessChecker,
        private readonly FormFactoryInterface $formFactory,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly SectionSettingsDefaults $settingsDefaults,
    ) {
    }

    private function getCsrfTokenManager(): CsrfTokenManagerInterface
    {
        return $this->csrfTokenManager;
    }

    #[Route(
        '/section/{id}/settings',
        name: 'content_blocks_section_settings',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+'],
    )]
    public function settings(int $id, Request $request): Response
    {
        $section = $this->em->find(Section::class, $id);
        if (!$section) {
            return new Response('', 404);
        }

        $area = $section->getContentArea();
        if (!$area || !$this->accessChecker->canEdit($area)) {
            throw new ContentBlocksAccessDeniedException();
        }

        // Initial form data: defaults backfill any keys the section's
        // current settings don't already have. This is what gives widgets
        // without an "empty" state (notably <input type="color">) a sane
        // starting value. Recursive merge so nested defaults (e.g.
        // ['styling' => ['backgroundColor' => '#ffffff']]) backfill into
        // the existing styling sub-form rather than replacing it.
        $current = $section->getEffectiveSettings(preferDraft: true);
        $initial = array_replace_recursive($this->settingsDefaults->get(), $current);

        // Number of (live) columns: drives whether the column-widths control
        // is offered and how many width inputs the sidebar renders.
        $columnCount = 0;
        foreach ($section->getColumns() as $column) {
            if (!$column->isDeleted()) {
                ++$columnCount;
            }
        }

        $form = $this->formFactory->create(SectionSettingsType::class, $initial, [
            'action' => '/_content-blocks/section/' . $id . '/settings',
            'method' => 'POST',
            'column_count' => $columnCount,
        ]);

        if ($request->isMethod('POST')) {
            if ($error = $this->csrfFailureOrNull($request)) {
                return $error;
            }

            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData() ?? [];
                $data['columnWidths'] = $this->sanitizeColumnWidths($data['columnWidths'] ?? null, $columnCount);
                $section->setDraftSettings($this->normalize($data));
                $this->em->flush();

                return new Response('', 204);
            }

            // Fall through to re-render with errors.
            return new Response(
                $this->twig->render('@ContentBlocks/builder/sidebar_section.html.twig', [
                    'form' => $form->createView(),
                    'sectionId' => $id,
                    'columnCount' => $columnCount,
                ]),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new Response($this->twig->render('@ContentBlocks/builder/sidebar_section.html.twig', [
            'form' => $form->createView(),
            'sectionId' => $id,
            'columnCount' => $columnCount,
        ]));
    }

    /**
     * Keep a column-widths CSV only when it's exactly $columnCount positive
     * integers summing to 100; otherwise drop it (→ equal widths). The
     * Stimulus controller already commits valid values, so this just guards
     * against direct/forged posts and keeps the stored JSON trustworthy.
     */
    private function sanitizeColumnWidths(mixed $value, int $columnCount): ?string
    {
        if (!\is_string($value) || $value === '' || $columnCount < 2) {
            return null;
        }

        $parts = explode(',', $value);
        if (\count($parts) !== $columnCount) {
            return null;
        }

        $sum = 0;
        $clean = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                return null;
            }
            $n = (int) $part;
            if ($n < 1 || $n > 99) {
                return null;
            }
            $sum += $n;
            $clean[] = (string) $n;
        }

        if ($sum !== 100) {
            return null;
        }

        return implode(',', $clean);
    }

    /**
     * Normalize the form payload before persisting. Empty maxWidth becomes
     * null; empty strings collapse to absent keys so the JSON column stays
     * tidy.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
