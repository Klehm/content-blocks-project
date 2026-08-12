#!/usr/bin/env node
// Generates one reference page per kit block under docs/kit/blocks/<type>.md,
// plus the sidebar fragment docs/.vitepress/data/blocks-nav.json.
//
// Config surface (options / choices / defaults) comes from the committed
// blocks.json — produced by `content-blocks-kit:blocks --format=json`, i.e. read
// straight from each block's describe(), so it can never drift from the code.
// The human prose comes from scripts/blocks-meta.mjs.
//
// Regenerate:  npm run docs:blocks          (from committed blocks.json)
//              npm run docs:blocks:refresh   (re-dumps blocks.json first, needs PHP)

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { blocksMeta } from './blocks-meta.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const docsRoot = join(here, '..');
const blocks = JSON.parse(readFileSync(join(docsRoot, '.vitepress/data/blocks.json'), 'utf8'));

const yamlValue = (v) => {
  if (v === '' ) return "''";
  if (v === null) return 'null';
  if (typeof v === 'boolean') return v ? 'true' : 'false';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
};

/** Markdown table from headers + rows (rows already stringified). */
const table = (headers, rows) => {
  if (rows.length === 0) return '';
  const head = `| ${headers.join(' | ')} |`;
  const sep = `| ${headers.map(() => '---').join(' | ')} |`;
  const body = rows.map((r) => `| ${r.join(' | ')} |`).join('\n');
  return `${head}\n${sep}\n${body}\n`;
};

const escapeCell = (s) => String(s).replace(/\|/g, '\\|');

function configSection(type, b) {
  const parts = [];

  const optionKeys = Object.keys(b.options ?? {});
  const choiceKeys = Object.keys(b.choices ?? {});
  const defaultKeys = Object.keys(b.defaults ?? {});

  // Options
  if (optionKeys.length) {
    parts.push('#### Options\n');
    parts.push('Block-level knobs, set under `options:`.\n');
    parts.push(
      table(
        ['Option', 'Default'],
        optionKeys.map((k) => [`\`${k}\``, `\`${yamlValue(b.options[k])}\``]),
      ),
    );
  }

  // Choices
  if (choiceKeys.length) {
    parts.push('#### Choice fields\n');
    parts.push('Selectable values. Restrict or reorder them per host with `choices:` (the default is shown in **bold**).\n');
    parts.push(
      table(
        ['Field', 'Values'],
        choiceKeys.map((k) => {
          const c = b.choices[k];
          const vals = c.values
            .map((v) => (v === c.default ? `**\`${escapeCell(yamlValue(v))}\`**` : `\`${escapeCell(yamlValue(v))}\``))
            .join(', ');
          return [`\`${k}\``, vals];
        }),
      ),
    );
  }

  // Defaults
  if (defaultKeys.length) {
    parts.push('#### Default data\n');
    parts.push('Initial values for a new block. Override per host with `defaults:`.\n');
    parts.push(
      table(
        ['Field', 'Default'],
        defaultKeys.map((k) => [`\`${k}\``, `\`${escapeCell(yamlValue(b.defaults[k]))}\``]),
      ),
    );
  }

  return parts.join('\n');
}

/** A copy-paste YAML config example tailored to what the block actually exposes. */
function configExample(type, b) {
  const lines = [`content_blocks_kit:`, `    blocks:`, `        ${type}:`];
  if (b.disabledByDefault) {
    lines.push(`            enabled: true                 # ${type} is disabled by default — opt in`);
  }
  const firstOption = Object.keys(b.options ?? {})[0];
  if (firstOption) {
    lines.push(`            options: { ${firstOption}: ${yamlValue(b.options[firstOption])} }`);
  }
  const firstChoice = Object.keys(b.choices ?? {})[0];
  if (firstChoice) {
    const vals = b.choices[firstChoice].values.slice(0, 2).map(yamlValue).join(', ');
    lines.push(`            choices: { ${firstChoice}: [${vals}] }   # restrict / reorder the picker`);
  }
  const firstDefault = Object.keys(b.defaults ?? {}).find((k) => typeof b.defaults[k] !== 'object');
  if (firstDefault) {
    lines.push(`            defaults: { ${firstDefault}: ${yamlValue(b.defaults[firstDefault])} }`);
  }
  return lines.join('\n');
}

function page(type, b, meta) {
  const out = [];
  out.push('---');
  out.push(`title: ${meta.tagline ? `${cap(type)} block` : cap(type)}`);
  out.push('---');
  out.push('');
  out.push(`# \`${type}\` — ${b.label}`);
  out.push('');
  out.push(`> ${meta.tagline}`);
  out.push('');
  if (b.disabledByDefault) {
    out.push('::: warning Disabled by default');
    out.push(`The \`${type}\` block is **not registered** unless you opt in with \`content_blocks_kit.blocks.${type}.enabled: true\`.`);
    out.push(':::');
    out.push('');
  }
  out.push(meta.intro);
  out.push('');
  if (meta.warning) {
    out.push('::: danger Security');
    out.push(meta.warning);
    out.push(':::');
    out.push('');
  }
  out.push('## When to use');
  out.push('');
  out.push(meta.whenToUse);
  out.push('');

  out.push('## Configuration');
  out.push('');
  out.push('Configure under `content_blocks_kit.blocks.' + type + '` (see [Configuring blocks](../configuration.md) for how the levers combine).');
  out.push('');
  const cfg = configSection(type, b);
  if (cfg.trim()) {
    out.push(cfg);
  } else {
    out.push('_This block exposes no options, choice fields, or overridable defaults._');
    out.push('');
  }

  out.push('#### Example');
  out.push('');
  out.push('```yaml');
  out.push('# config/packages/content_blocks_kit.yaml');
  out.push(configExample(type, b));
  out.push('```');
  out.push('');

  out.push('## Front-end');
  out.push('');
  out.push(`Rendered markup: ${meta.markup} Style it by overriding the \`--cb-kit-*\` custom properties (see [the kit stylesheet](../index.md#front-stylesheet-required)).`);
  out.push('');
  if (meta.controller) {
    // A block may be served by more than one controller (the rich-text
    // block, one per selectable editor), so a list is allowed too.
    const controllers = [].concat(meta.controller);
    out.push('::: tip Requires a Stimulus controller');
    out.push(
      `Enable ${controllers.map((c) => `\`${c}\``).join(' / ')} under \`@klehm/content-blocks-kit\``
      + ` in your host \`assets/controllers.json\`${controllers.length > 1 ? ' (whichever you select — both, if you are unsure)' : ''}.`,
    );
    out.push(':::');
    out.push('');
  }
  // Free-form prose sections, for blocks whose surface needs more than the
  // generated tables can carry.
  for (const section of meta.sections ?? []) {
    out.push(`## ${section.title}`);
    out.push('');
    out.push(section.body.trim());
    out.push('');
  }
  if (meta.notes && meta.notes.length) {
    out.push('## Notes');
    out.push('');
    for (const n of meta.notes) out.push(`- ${n}`);
    out.push('');
  }
  out.push('---');
  out.push('');
  out.push('_The configuration tables above are generated from `content-blocks-kit:blocks --format=json`, read straight from the block\'s code — they never go stale._');
  out.push('');
  return out.join('\n');
}

const cap = (s) => s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');

// --- run -------------------------------------------------------------------
const outDir = join(docsRoot, 'kit/blocks');
mkdirSync(outDir, { recursive: true });

const nav = [];
const types = Object.keys(blocks).sort(
  (a, z) => (blocksMeta[a]?.order ?? 99) - (blocksMeta[z]?.order ?? 99),
);

let written = 0;
for (const type of types) {
  const b = blocks[type];
  const meta = blocksMeta[type];
  if (!meta) {
    console.warn(`⚠  no prose meta for block "${type}" — skipping (add it to blocks-meta.mjs)`);
    continue;
  }
  writeFileSync(join(outDir, `${type}.md`), page(type, b, meta));
  nav.push({ text: `${type} — ${b.label}`, link: `/kit/blocks/${type}` });
  written++;
}

writeFileSync(join(docsRoot, '.vitepress/data/blocks-nav.json'), JSON.stringify(nav, null, 2) + '\n');
console.log(`✓ generated ${written} block page(s) + blocks-nav.json`);
