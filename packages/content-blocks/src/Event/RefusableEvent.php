<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A "before" event a listener can refuse. Every listener still runs, so each
 * can add its reason; the action is skipped if any refused.
 *
 * @see docs/guide/events.md#refusing-an-action
 */
abstract class RefusableEvent extends Event
{
    /** @var list<string> */
    private array $reasons = [];

    /** Shown to the editor as is: pass a message already translated. */
    public function refuse(string $reason): void
    {
        $this->reasons[] = $reason;
    }

    public function isRefused(): bool
    {
        return $this->reasons !== [];
    }

    /** @return list<string> */
    public function getReasons(): array
    {
        return $this->reasons;
    }
}
