<?php

declare(strict_types=1);

namespace ContentBlocks\History;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Identifies whose undo stack a request belongs to.
 *
 * @see docs/internals/history.md#whose-stack-is-it
 *
 * @internal
 */
final class BuilderSession
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The HTTP session, hashed — it survives a reload, differs per editor and
     * needs no client plumbing, which the Live Component sidebar has none of.
     *
     * @see docs/internals/history.md#whose-stack-is-it
     */
    public function id(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }

        $id = $request->getSession()->getId();

        return $id === '' ? null : substr(hash('sha256', $id), 0, 32);
    }
}
