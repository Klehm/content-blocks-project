<?php

declare(strict_types=1);

namespace ContentBlocks\Versioning;

/**
 * Decides what to do with stored content whose schema generation is not the one
 * the app runs today — the host-owned `content_blocks.content_version`.
 *
 * The bundle aliases this to {@see DenyOnMismatchUpgrader}; a host that wants to
 * migrate on read, or to accept a generation it knows is compatible, aliases it
 * to its own implementation.
 *
 * **Where it applies.** Section templates only. Their stored version is a number
 * this same installation issued, so comparing it means something. An imported
 * payload's `contentVersion` belongs to the app that exported it — "12" there
 * and "12" here have no relation — so the import flow does not consult this
 * seam; it judges content by shape instead (unusable blocks are skipped and
 * reported). A host that controls both ends of a transfer and wants to gate it
 * on a version can decorate {@see \ContentBlocks\Transfer\ContentAreaImporterInterface}.
 *
 * **Upgrading is transient.** Whatever {@see upgrade()} returns is instantiated,
 * not written back to the template row. A permanent rewrite is a migration, and
 * stays the host's job.
 */
interface ContentVersionUpgraderInterface
{
    /**
     * Cheap predicate: could this generation be used at all? Called once per row
     * when listing the section-template library, so the picker can rule a
     * template out before the editor clicks it. Keep it free of side effects and
     * of anything that touches the payload — {@see upgrade()} does the work.
     *
     * @param int|null $stored version stamped on the stored content; null means it predates versioning
     * @param int      $current the app's configured `content_blocks.content_version`
     */
    public function supports(?int $stored, int $current): bool;

    /**
     * Bring a payload up to the current generation, or refuse it.
     *
     * Called only when the content is about to be used, and only for payloads
     * {@see supports()} accepted — a mismatch that reaches here is a stale
     * listing or a hand-crafted request, so throwing is a legitimate outcome.
     *
     * @param array<string, mixed> $payload
     * @param int|null             $stored
     *
     * @return array<string, mixed> the payload as the current generation expects it
     *
     * @throws IncompatibleContentVersionException when the gap cannot be bridged
     */
    public function upgrade(array $payload, ?int $stored, int $current): array;
}
