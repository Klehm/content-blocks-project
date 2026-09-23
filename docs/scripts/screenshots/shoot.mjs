// Takes the documentation screenshots from pages seeded by seed.php.
// Usage: node shoot.mjs <out-dir> '<seed.php JSON>' [base-url]. See README.md
import { chromium } from '../../../packages/content-blocks/node_modules/playwright/index.mjs';
import { mkdirSync } from 'node:fs';

const [out, json, base = 'http://127.0.0.1:8006'] = process.argv.slice(2);
const seed = JSON.parse(json);
const { page: pageId, area: areaId } = seed.showcase;
mkdirSync(`${out}/kit`, { recursive: true });

const browser = await chromium.launch();
const ctx = await browser.newContext({
  viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, locale: 'en-US',
});
const page = await ctx.newPage();
// The sandbox's demo fragment is not something a host would ship.
const hide = '[data-sandbox-shell-fragment]{display:none!important}';
const settle = (ms = 1200) => page.waitForTimeout(ms);
// The preview's left margin, outside every section: nothing shows a hover
// affordance there, and leaving the iframe would not clear one.
const rest = () => page.mouse.move(350, 700);

// The builder, a block open in the sidebar.
await page.goto(`${base}/admin/page/${pageId}`);
await page.addStyleTag({ content: hide });
await page.locator('.cb-launcher__button').click();
await page.locator('.cb-shell').waitFor();
const frame = page.frameLocator('.cb-shell__iframe');
await frame.locator('[data-cb-block-type="title"]').first().waitFor();
await settle(1500);
await frame.locator('[data-cb-block-type="button_group"]').first().click();
await page.locator('.cb-block__edit-form').waitFor();
await rest();
await settle();
await page.screenshot({ path: `${out}/builder.png` });

// A section's sidebar: its tabs and panels.
// Scrolled first, so the pointer never rests over another section.
const featured = frame.locator('[data-cb-section-id]').nth(1);
await featured.evaluate((el) => el.scrollIntoView({ block: 'center' }));
await settle(400);
await featured.click({ position: { x: 12, y: 12 } });
await page.locator('.cb-sidebar__section-settings .cb-sidebar-tabs__tab').first().waitFor();
await page.locator('.cb-sidebar__section-settings summary', { hasText: 'Layout' }).click();
await rest();
await settle();
await page.screenshot({ path: `${out}/section-sidebar.png` });

// The navigator, floating over the preview.
await page.locator('.cb-shell__tree-toggle').click();
await page.locator('.cb-tree').waitFor({ state: 'visible' });
await rest();
await settle();
await page.screenshot({ path: `${out}/navigator.png` });
await page.locator('.cb-shell__tree-toggle').click();

// The same page previewed on a phone.
await page.locator('[data-cb-builder-viewport-param="mobile"]').click();
await settle(1500);
await frame.locator('body').evaluate(() => window.scrollTo(0, 0));
await rest();
await settle(800);
await page.screenshot({ path: `${out}/builder-mobile.png` });
await page.locator('[data-cb-builder-viewport-param="desktop"]').click();

// The workbench, half translated so the three states show.
const fr = {
  'Pages your team can build, on the site you already run': 'Des pages construites par votre équipe',
  'Sections, columns and blocks, edited over a live preview of your own page. Drafts stay drafts until you publish.': 'Sections, colonnes et blocs, édités sur l\'aperçu de votre page. Rien n\'est en ligne avant la publication.',
  'Get started': 'Commencer',
  'Read the docs': 'La documentation',
  '/start': '/fr/demarrer',
  '/docs': '/fr/docs',
  'Café': 'Café',
  'Menus that the team updates.': 'Des menus que l\'équipe met à jour.',
  'Travel': 'Voyage',
  'One page per destination.': 'Une page par destination.',
  'Open': 'Ouvrir',
  'A team at work': 'Une équipe au travail',
  'Fast': 'Rapide',
  'The preview is your real page, refreshed in place as you edit.': 'L\'aperçu est votre vraie page, rafraîchie pendant l\'édition.',
  'Safe': 'Sûr',
  'Editors change drafts; the published page moves on Publish only.': 'Les éditeurs modifient un brouillon ; la page en ligne change à la publication.',
  'Made with blocks': 'Fait avec des blocs',
  'Studio': 'Studio',
  'A portfolio in an afternoon.': 'Un portfolio en un après-midi.',
};
// Wider, so the preview pane shows the hero as a desktop does.
await page.setViewportSize({ width: 1680, height: 1050 });
await page.goto(`${base}/admin/translations/workbench/${areaId}/fr`);
await page.waitForLoadState('networkidle');
for (const row of await page.locator('[data-target="row"]').all()) {
  const src = (await row.locator('.cb-wb__source-text').innerText()).trim();
  if (fr[src] !== undefined) await row.locator('[data-target="input"]').fill(fr[src]);
}
// Saves are debounced per block: let the last ones land before reloading.
await page.locator('.cb-wb__back').focus();
await settle(4000);
await page.waitForLoadState('networkidle');
await page.reload();
await page.waitForLoadState('networkidle');
await settle(1500);
await page.screenshot({ path: `${out}/workbench.png` });

// One image per kit block, cropped to its section.
await page.setViewportSize({ width: 1100, height: 900 });
await page.goto(`${base}/page/${seed.kit.page}`);
await page.waitForLoadState('networkidle');
await settle(1500);
const sections = page.locator('.cb-section');
for (const [i, type] of seed.kit.types.entries()) {
  const section = sections.nth(i);
  await section.scrollIntoViewIfNeeded();
  // An accordion shows more with its first panel open.
  await section.locator('details').evaluateAll((all) => { if (all[0]) all[0].open = true; });
  await settle(300);
  await section.screenshot({ path: `${out}/kit/${type}.png` });
}

// The gallery's thumbnails: the block alone, at a column's width.
mkdirSync(`${out}/kit/thumbs`, { recursive: true });
await page.setViewportSize({ width: 560, height: 900 });
await settle(800);
for (const [i, type] of seed.kit.types.entries()) {
  const block = sections.nth(i).locator('.cb-block').first();
  await block.scrollIntoViewIfNeeded();
  await settle(200);
  await block.screenshot({ path: `${out}/kit/thumbs/${type}.png` });
}

await browser.close();
