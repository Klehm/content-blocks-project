<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Twig;

use ContentBlocks\Sylius\Slot\SlotRenderer;
use ContentBlocks\Sylius\Slot\SlotType;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Taxonomy\Model\TaxonInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Prints a native page's builder content from the shop's hooks. */
final class SlotExtension extends AbstractExtension
{
    /** @param TaxonRepositoryInterface<TaxonInterface> $taxons */
    public function __construct(
        private readonly SlotRenderer $slots,
        private readonly TaxonRepositoryInterface $taxons,
        private readonly LocaleContextInterface $locale,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_sylius_slot', $this->slot(...), ['is_safe' => ['html']]),
            new TwigFunction('cb_sylius_taxon_slot', $this->taxonSlot(...), ['is_safe' => ['html']]),
        ];
    }

    public function slot(string $type, ?string $subject): string
    {
        return $subject === null || $subject === '' ? '' : ($this->slots->render($type, $subject) ?? '');
    }

    /** The taxon listing names its taxon by the slug in the route only. */
    public function taxonSlot(): string
    {
        $slug = $this->requestStack->getCurrentRequest()?->attributes->get('slug');
        if (!\is_string($slug) || $slug === '' || !$this->slots->isEnabled(SlotType::TAXON)) {
            return '';
        }
        $taxon = $this->taxons->findOneBySlug($slug, $this->locale->getLocaleCode());

        return $this->slot(SlotType::TAXON, $taxon?->getCode());
    }
}
