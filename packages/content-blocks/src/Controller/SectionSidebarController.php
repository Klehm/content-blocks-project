<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use ContentBlocks\Entity\Section;
use ContentBlocks\Form\Type\SectionSettingsType;
use ContentBlocks\Section\SectionSettingsDefaults;
use ContentBlocks\Section\SectionStyleRegistry;
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
        private readonly SectionStyleRegistry $styleRegistry,
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

        // Initial form data, three layers (rightmost wins per key):
        //   defaults ← preset settings ← the section's saved settings.
        // Defaults backfill keys so widgets without an "empty" state get a
        // sane starting value; the selected preset's settings backfill next,
        // so flipping "Customize styling" on presents the preset's values as
        // the starting point instead of blank fields.
        $current = $section->getEffectiveSettings(preferDraft: true);
        $initial = array_replace_recursive(
            $this->settingsDefaults->get(),
            $this->presetSettings($current),
            $current,
        );

        // Sections saved before the stylingCustom switch existed carry
        // styling values but no flag: treat them as customized so their
        // values stay visible (and survive the next save).
        $initial['stylingCustom'] ??= ($current['styling'] ?? []) !== [];

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
                // Customize-styling switch off → drop the styling subtree
                // entirely: the preset (if any) applies untouched, and a
                // later preset change never fights values persisted while
                // the fields were hidden. The false flag itself is pruned by
                // normalize(); its absence reads as "off" on the next GET.
                if (($data['stylingCustom'] ?? false) !== true) {
                    unset($data['styling']);
                }
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
     * Settings carried by the currently selected style preset, or [] when
     * no preset is selected / the preset is class-only.
     *
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private function presetSettings(array $current): array
    {
        $styleName = $current['styleName'] ?? null;
        if (!\is_string($styleName) || $styleName === '') {
            return [];
        }

        return $this->styleRegistry->get($styleName)?->settings ?? [];
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
     * Normalize the form payload before persisting: recursively drop empty
     * or default-off leaves (null, '', false, and arrays left empty after
     * pruning) so the JSON column only carries values the user actually
     * set. 0 is meaningful (zero padding) and is kept.
     *
     * Untouched styling fields submit as nulls; persisting them would mask
     * a preset's values on the next sidebar prefill (an explicit null would
     * "win" over the preset in the defaults ← preset ← current merge).
     * Unchecked checkboxes (stylingCustom, the spacing "linked" toggles)
     * prune the same way: their absence reads as false everywhere the
     * settings are consumed.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $value = $this->normalize($value);
            }
            if ($value === null || $value === '' || $value === false || $value === []) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
