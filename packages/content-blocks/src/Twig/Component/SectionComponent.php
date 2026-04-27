<?php

declare(strict_types=1);

namespace ContentBlocks\Twig\Component;

use ContentBlocks\Entity\Section;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('ContentBlocks:Section', template: '@ContentBlocks/components/Section.html.twig')]
final class SectionComponent
{
    public Section $section;
}
