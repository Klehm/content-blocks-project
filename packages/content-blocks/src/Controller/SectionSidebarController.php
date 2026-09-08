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
 * The section settings form: GET renders it, POST saves to `draft_settings`
 * and answers 204, or re-renders with errors and 422.
 *
 * @see docs/internals/forms.md#why-untouched-fields-are-pruned-on-save
 *
 * @internal the routes are the contract, not this class
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

        // Three layers, rightmost winning per key:
        //   defaults <- preset settings <- the section's saved settings.
        $current = $section->getEffectiveSettings(preferDraft: true);
        $initial = array_replace_recursive(
            $this->settingsDefaults->get(),
            $this->presetSettings($current),
            $current,
        );

        // Sections predating the switch carry styling but no flag; treat them
        // as customized so their values survive the next save.
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
                // Off drops the subtree, so a later preset change never fights
                // values persisted while the fields were hidden.
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
     *
     * @return array<string, mixed>
     */
    private function presetSettings(array $current): array
    {
        $styleName = $current['styleName'] ?? null;
        if (!\is_string($styleName) || $styleName === '') {
            return [];
        }

        return $this->styleRegistry->get($styleName)->settings ?? [];
    }

    /**
     * Kept only when it is exactly $columnCount positive integers summing to
     * 100; anything else drops to equal widths. Guards against forged posts.
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
     * Recursively drops empty and default-off leaves so the JSON carries only
     * what the user set. `0` is meaningful (zero padding) and survives.
     *
     * @see docs/internals/forms.md#why-untouched-fields-are-pruned-on-save
     *
     * @param array<string, mixed> $data
     *
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
