/**
 * Sidebar fields live in tabs (`cb_group`), collapsible panels (`cb_panel`)
 * and folded collection entries: a spec reaching a field opens all three.
 */

/** Opens the tab, every closed panel, then every folded entry. */
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
        const entries = [];
        for (let entry = node.closest('.cb-form-collection__item--collapsed'); entry;
            entry = entry.parentElement?.closest('.cb-form-collection__item--collapsed')) {
            entries.unshift(entry);
        }
        entries.forEach((entry) => entry
            .querySelector(':scope > .cb-form-collection__controls > .cb-form-collection__toggle')
            .click());
    });

    return locator;
}

/** Opens a sidebar tab by its visible name. */
export async function openTab(sidebar, name) {
    await sidebar.locator('.cb-sidebar-tabs__tab', { hasText: name }).click();
}
