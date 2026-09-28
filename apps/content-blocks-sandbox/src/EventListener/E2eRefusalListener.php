<?php

declare(strict_types=1);

namespace App\EventListener;

use ContentBlocks\Event\BeforeBlockDeleteEvent;
use ContentBlocks\Event\BeforeBlockSaveEvent;
use ContentBlocks\Event\BeforeContentAreaDiscardEvent;
use ContentBlocks\Event\BeforeContentAreaPublishEvent;
use ContentBlocks\Event\RefusableEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Refuses the actions the `cb_e2e_refuse` cookie names (comma-separated:
 * publish, discard, block.save, block.delete), so Playwright can pin what a
 * refusal does in the builder. Guarded on kernel.debug, like E2eAccessChecker.
 */
final class E2eRefusalListener
{
    public const COOKIE = 'cb_e2e_refuse';

    public function __construct(
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    #[AsEventListener]
    public function onPublish(BeforeContentAreaPublishEvent $event): void
    {
        $this->refuseIfNamed('publish', $event);
    }

    #[AsEventListener]
    public function onDiscard(BeforeContentAreaDiscardEvent $event): void
    {
        $this->refuseIfNamed('discard', $event);
    }

    #[AsEventListener]
    public function onBlockSave(BeforeBlockSaveEvent $event): void
    {
        $this->refuseIfNamed('block.save', $event);
    }

    #[AsEventListener]
    public function onBlockDelete(BeforeBlockDeleteEvent $event): void
    {
        $this->refuseIfNamed('block.delete', $event);
    }

    private function refuseIfNamed(string $action, RefusableEvent $event): void
    {
        if (!$this->debug) {
            return;
        }
        $cookie = (string) $this->requestStack->getMainRequest()?->cookies->get(self::COOKIE);
        if (\in_array($action, explode(',', $cookie), true)) {
            $event->refuse(sprintf('Refused by the e2e listener (%s).', $action));
        }
    }
}
