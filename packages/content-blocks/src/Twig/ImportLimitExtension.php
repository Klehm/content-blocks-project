<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Transfer\ImportSizeLimit;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `cb_import_max_bytes()`, so the builder refuses an oversized import
 * file before sending it rather than after the server drops it.
 */
final class ImportLimitExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImportSizeLimit $limit,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_import_max_bytes', $this->limit->bytes(...)),
        ];
    }
}
