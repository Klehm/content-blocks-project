<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

/**
 * Thrown by the publisher when a listener refused the publish or discard.
 * Nothing was written.
 *
 * @see docs/guide/events.md#refusing-an-action
 */
final class ActionRefusedException extends \RuntimeException
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct(implode(' ', $reasons));
    }

    public static function from(RefusableEvent $event): self
    {
        return new self($event->getReasons());
    }
}
