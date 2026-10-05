<?php

declare(strict_types=1);

namespace App\Builder;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Builder\ConfiguredBuilderStructureResolver;
use ContentBlocks\Entity\ContentArea;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The configured structure, unless the `cb_e2e_structure` cookie names
 * another one (`hidden`, `fixed`, `editable:nocolumns`…), so the Playwright
 * suite can drive every mode against one sandbox.
 *
 * Guarded on kernel.debug, like E2eAccessChecker.
 */
final class E2eBuilderStructureResolver implements BuilderStructureResolverInterface
{
    public const COOKIE = 'cb_e2e_structure';

    public function __construct(
        private readonly ConfiguredBuilderStructureResolver $configured,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function forArea(ContentArea $area): BuilderStructure
    {
        $cookie = $this->debug ? $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE) : null;
        if (!\is_string($cookie) || $cookie === '') {
            return $this->configured->forArea($area);
        }

        [$sections, $columns] = explode(':', $cookie, 2) + [1 => ''];
        if (!\in_array($sections, BuilderStructure::SECTIONS, true)) {
            return $this->configured->forArea($area);
        }

        return new BuilderStructure($sections, $columns !== 'nocolumns');
    }
}
