<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Contributes markup to the builder shell.
 *
 * Implementations are autoconfigured (tag `content_blocks.builder_shell_extension`)
 * — declare the service and it is picked up.
 *
 * ---- Why this exists next to BuilderActionProviderInterface ----
 *
 * An action provider gets a bundle a button in the Actions menu, and the click
 * comes back as a `cb:builder:action` event. That is the whole contract: what
 * happens next is "the host's business". For a *host* that is right — it owns
 * the page the builder is mounted in and can listen from anywhere. A *bundle*
 * owns no page. Until now its only ways into the builder window were a Stimulus
 * controller (which every host must enable by hand, under AssetMapper and
 * Encore alike) or asking the host to write the listener for it.
 *
 * A shell extension is the missing half. Its fragments are rendered inside the
 * shell itself, wherever the shell is rendered — through `ContentAreaType` or a
 * direct include of the launcher — so a bundle can ship its own `<dialog>`, its
 * own status line and a `<script type="module">` pointing at a route it serves,
 * and the host wires nothing. The script can then listen for the bundle's own
 * `cb:builder:action` key on the shell, call the bundle's endpoints, and tell
 * the builder to catch up with a `cb:area:changed` event.
 *
 * The area is passed so an extension can decide per-area — returning nothing
 * is how a fragment hides itself (e.g. when the current user may not use it).
 */
interface BuilderShellExtensionInterface
{
    /**
     * @return iterable<BuilderShellFragment>
     */
    public function getFragments(ContentArea $area): iterable;
}
