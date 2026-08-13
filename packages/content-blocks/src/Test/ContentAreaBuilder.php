<?php

declare(strict_types=1);

namespace ContentBlocks\Test;

use ContentBlocks\Entity\ContentArea;

/**
 * Builds a `ContentArea` entity tree for tests and fixtures.
 *
 * The tree has two states per node — draft and published — and getting a
 * fixture wrong is silent: content written to the draft side renders in the
 * builder and leaves the public page empty, because that page reads the
 * published side. So the builder publishes by default, and `draft()` is the
 * explicit opt-out. Positions auto-increment in insertion order.
 *
 * The entities are built in memory and nothing is persisted: pass the result
 * to `EntityManager::persist()` yourself when a test needs a database.
 *
 * ```php
 * $area = ContentAreaBuilder::create()
 *     ->section(fn (SectionBuilder $s) => $s
 *         ->layout(Section::LAYOUT_TWO_COLS)
 *         ->column(fn (ColumnBuilder $c) => $c->preset('col-6')->block('text', ['content' => 'Left']))
 *         ->column(fn (ColumnBuilder $c) => $c->preset('col-6')->block('text', ['content' => 'Right'])))
 *     ->build();
 * ```
 *
 * @experimental Shipped in `src/` so host applications can use it in their own
 *               test suites, but the shape is driven by this package's suite
 *               first. It is the one surface here not covered by the BC promise
 *               until it has survived a release cycle; see FREEZE-AUDIT.md.
 */
final class ContentAreaBuilder
{
    use SetsEntityId;

    private ?int $id = null;

    private ?\DateTimeImmutable $updatedAt = null;

    private ?int $contentVersion = null;

    private bool $published = true;

    /** @var list<SectionBuilder> */
    private array $sections = [];

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * Stamps the (normally database-generated) primary key.
     *
     * Any test that looks an area up by id, or asserts on a generated URL,
     * needs one before the entity has ever seen a database.
     */
    public function withId(int $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function updatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function contentVersion(?int $version): self
    {
        $this->contentVersion = $version;

        return $this;
    }

    /**
     * Builds the whole tree as never-published draft, the state an area is in
     * between an editor's change and their Publish click.
     */
    public function draft(): self
    {
        $this->published = false;

        return $this;
    }

    /**
     * @param (callable(SectionBuilder): mixed)|null $configure
     */
    public function section(?callable $configure = null): self
    {
        $section = new SectionBuilder();
        if ($configure !== null) {
            $configure($section);
        }
        $this->sections[] = $section;

        return $this;
    }

    /**
     * Materializes the tree. Nothing is built before this call, so the order
     * of the calls above never matters; and each call returns an independent
     * tree, so one builder can seed several tests.
     */
    public function build(): ContentArea
    {
        $area = new ContentArea();
        if ($this->id !== null) {
            $this->setEntityId($area, $this->id);
        }
        if ($this->updatedAt !== null) {
            $area->setUpdatedAt($this->updatedAt);
        }
        if ($this->contentVersion !== null) {
            $area->setContentVersion($this->contentVersion);
        }

        foreach ($this->sections as $i => $section) {
            $area->addSection($section->build($this->published, $i));
        }

        return $area;
    }
}
