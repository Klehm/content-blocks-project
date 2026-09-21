/**
 * Sidebar fields live in tabs (`cb_group`) and collapsible panels
 * (`cb_panel`): a spec reaching a field opens both, as an editor would.
 */

/** Opens the tab, then every closed panel, holding the element. */
export async function reveal(locator) {
    await locator.first().evaluate((node) => {
        const panel = node.closest('[role="tabpanel"][data-cb-tab]');
        if (panel?.hidden) {
            panel.closest('.cb-sidebar-tabs')
                ?.querySelector(`[role="tab"][data-cb-tab="${panel.dataset.cbTab}"]`)
                ?.click();
        }
        const folds = [];
        for (let fold = node.closest('details'); fold; fold = fold.parentElement?.closest('details')) {
            folds.unshift(fold);
        }
        folds.forEach((fold) => {
            if (!fold.open) fold.querySelector(':scope > summary').click();
        });
    });

    return locator;
}

/** Opens a sidebar tab by its visible name. */
export async function openTab(sidebar, name) {
    await sidebar.locator('.cb-sidebar-tabs__tab', { hasText: name }).click();
}
