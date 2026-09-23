# Documentation screenshots

Everything under `docs/public/screenshots/` comes from here: the builder tour
on the home page, the translation workbench, and one render per kit block (the
full image on the block's page, a tight thumbnail in the kit gallery).

```bash
docs/scripts/screenshots/run.sh
```

It needs the main sandbox installed with its database, `ffmpeg` with libwebp,
Playwright's Chromium (`packages/content-blocks/node_modules`), and the
network: photos come from picsum.photos. Port 8006 must be free.

What it does, in order:

1. switches the sandbox to English (UI and content source, French as a
   translation target), and puts it back on exit, error included;
2. `seed.php` creates two published pages, a showcase and one section per kit
   block, then `content-blocks:backfill-collection-ids` gives collection
   entries the ids the builder would have minted;
3. `shoot.mjs` drives the builder, the workbench and the public kit page;
4. converts every PNG to WebP and regenerates the block pages, which show an
   image only once its file exists.

Each run adds two pages to the sandbox. A new kit block needs an entry in
`$kit` in `seed.php`, in the kit's order.

When a shot looks wrong, look at the PNG sources the run prints before
blaming the page: hover state and scroll position carry over from one shot to
the next, which is why `shoot.mjs` parks the pointer and scrolls explicitly.
