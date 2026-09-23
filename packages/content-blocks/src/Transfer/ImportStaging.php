<?php

declare(strict_types=1);

namespace ContentBlocks\Transfer;

use ContentBlocks\Entity\ContentArea;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Between the steps of an import, what the server itself verified: the files
 * the plan expects, and where each one it has already checked is stored.
 *
 * @see docs/internals/transfer.md#an-import-in-steps
 *
 * @internal
 */
final class ImportStaging
{
    private const KEY = 'content_blocks.import.';

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    /**
     * A new plan starts over, but keeps what an interrupted one already stored.
     *
     * @param list<string> $hashes
     */
    public function expect(ContentArea $area, array $hashes): void
    {
        $state = $this->read($area);
        $state['expected'] = array_fill_keys($hashes, true);
        $this->write($area, $state);
    }

    public function isExpected(ContentArea $area, string $hash): bool
    {
        return isset($this->read($area)['expected'][$hash]);
    }

    public function stage(ContentArea $area, string $hash, string $path): void
    {
        $state = $this->read($area);
        $state['stored'][$hash] = $path;
        $this->write($area, $state);
    }

    public function storedPath(ContentArea $area, string $hash): ?string
    {
        return $this->read($area)['stored'][$hash] ?? null;
    }

    /**
     * @return array<string, string> hash => stored path
     */
    public function stored(ContentArea $area): array
    {
        return $this->read($area)['stored'];
    }

    public function clear(ContentArea $area): void
    {
        $this->requests->getSession()->remove(self::KEY . $area->getId());
    }

    /**
     * @return array{
     *     expected: array<string, true>,
     *     stored: array<string, string>,
     * }
     */
    private function read(ContentArea $area): array
    {
        $state = $this->requests->getSession()->get(self::KEY . $area->getId());

        return [
            'expected' => \is_array($state['expected'] ?? null) ? $state['expected'] : [],
            'stored' => \is_array($state['stored'] ?? null) ? $state['stored'] : [],
        ];
    }

    /**
     * @param array{
     *     expected: array<string, true>,
     *     stored: array<string, string>,
     * } $state
     */
    private function write(ContentArea $area, array $state): void
    {
        $this->requests->getSession()->set(self::KEY . $area->getId(), $state);
    }
}
