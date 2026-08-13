/**
 * Fails when a `--cb-*` custom property is declared in a package stylesheet but
 * documented nowhere.
 *
 * From 1.0 the token names are public surface (see FREEZE-AUDIT.md): a host may
 * override any of them and expect it to survive the 1.x line. That promise is
 * only honest if the documented list is the declared list — an undocumented
 * token is frozen just the same, and nobody knows it exists. This check is what
 * keeps the two from drifting apart on the next reskin.
 *
 * Run: npm run docs:check-tokens   (from docs/)
 */

import { readFileSync } from 'node:fs';
import { readdir } from 'node:fs/promises';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const TOKEN = /--cb-[a-z0-9-]+/g;
const DECLARATION = /^\s*(--cb-[a-z0-9-]+)\s*:/gm;

async function filesUnder(dir, ext) {
    const out = [];
    let entries;
    try {
        entries = await readdir(dir, { withFileTypes: true });
    } catch {
        return out;
    }
    for (const entry of entries) {
        if (entry.name === 'node_modules' || entry.name === 'dist' || entry.name.startsWith('.')) continue;
        const full = join(dir, entry.name);
        if (entry.isDirectory()) out.push(...(await filesUnder(full, ext)));
        else if (entry.name.endsWith(ext)) out.push(full);
    }
    return out;
}

const declared = new Map(); // token -> defining file
for (const file of await filesUnder(join(root, 'packages'), '.css')) {
    const css = readFileSync(file, 'utf8');
    for (const [, token] of css.matchAll(DECLARATION)) {
        if (!declared.has(token)) declared.set(token, relative(root, file));
    }
}

const documented = new Set();
for (const file of await filesUnder(join(root, 'docs'), '.md')) {
    for (const token of readFileSync(file, 'utf8').matchAll(TOKEN)) documented.add(token[0]);
}

const missing = [...declared.keys()].filter((t) => !documented.has(t)).sort();

if (missing.length > 0) {
    console.error(`\n${missing.length} CSS token(s) declared but not documented:\n`);
    for (const token of missing) console.error(`  ${token.padEnd(32)} ${declared.get(token)}`);
    console.error(
        '\nEvery --cb-* token is public surface from 1.0. Document it (styling.md for the\n' +
            'builder chrome, kit/index.md for content, translation.md for the workbench), or\n' +
            'rename it out of the --cb-* namespace if it was never meant to be overridable.\n'
    );
    process.exit(1);
}

console.log(`All ${declared.size} --cb-* tokens are documented.`);
