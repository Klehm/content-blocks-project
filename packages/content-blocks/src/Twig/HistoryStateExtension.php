<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\History\ActionJournal;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the undo stack's starting state as `cb_history_state(area)`, which
 * the shell calls itself rather than receiving as a variable.
 *
 * @see docs/internals/history.md#the-buttons-and-their-state
 */
final class HistoryStateExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActionJournal $journal,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_history_state', $this->forArea(...)),
        ];
    }

    /**
     * @return array{canUndo: bool, canRedo: bool}
     */
    public function forArea(ContentArea $area): array
    {
        return $this->journal->state($area);
    }
}
