<?php

declare(strict_types=1);

namespace ContentBlocks\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One undoable builder action, as the pair of state assignments that move the
 * draft between before and after.
 *
 * @see docs/internals/history.md#what-an-entry-holds
 */
#[ORM\Entity]
#[ORM\Table(name: 'cb_action_log')]
#[ORM\Index(name: 'cb_action_log_stack', columns: ['content_area_id', 'session_id', 'seq'])]
class ActionLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentArea::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ContentArea $contentArea = null;

    /**
     * Hashed session id: two editors must not undo each other's work, and the
     * raw id is a credential.
     *
     * @see docs/internals/history.md#whose-stack-is-it
     */
    #[ORM\Column(name: 'session_id', length: 64)]
    private string $sessionId = '';

    /** Monotonic within (area, session). The stack order, not a timestamp. */
    #[ORM\Column(type: 'integer')]
    private int $seq = 0;

    #[ORM\Column(length: 40)]
    private string $label = '';

    /**
     * What a follow-up entry has to match to be merged into this one.
     *
     * @see docs/internals/history.md#coalescing-a-run-of-typing
     */
    #[ORM\Column(name: 'coalesce_key', length: 100, nullable: true)]
    private ?string $coalesceKey = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'undo_ops', type: 'json')]
    private array $undoOps = [];

    /** @var list<array<string, mixed>> */
    #[ORM\Column(name: 'redo_ops', type: 'json')]
    private array $redoOps = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $undone = false;

    /** Start of a coalesced run; {@see $updatedAt} is its last merge. */
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getContentArea(): ?ContentArea
    {
        return $this->contentArea;
    }

    public function setContentArea(?ContentArea $contentArea): self
    {
        $this->contentArea = $contentArea;

        return $this;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function setSeq(int $seq): self
    {
        $this->seq = $seq;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getCoalesceKey(): ?string
    {
        return $this->coalesceKey;
    }

    public function setCoalesceKey(?string $coalesceKey): self
    {
        $this->coalesceKey = $coalesceKey;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getUndoOps(): array
    {
        return $this->undoOps;
    }

    /** @param list<array<string, mixed>> $ops */
    public function setUndoOps(array $ops): self
    {
        $this->undoOps = $ops;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getRedoOps(): array
    {
        return $this->redoOps;
    }

    /** @param list<array<string, mixed>> $ops */
    public function setRedoOps(array $ops): self
    {
        $this->redoOps = $ops;

        return $this;
    }

    public function isUndone(): bool
    {
        return $this->undone;
    }

    public function setUndone(bool $undone): self
    {
        $this->undone = $undone;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
