import { describe, it, expect } from 'vitest';
import { audit, accessibleName, normalize, countWords, CHECKS } from '../audit.js';

/** A page as the preview renders it with `cb_chrome=0`. */
function page(area, { before = '<h1>Page title</h1>', after = '' } = {}) {
    return new DOMParser().parseFromString(`<!doctype html><html><body>
        <header>${before}</header>
        <main><div class="cb-content-area" data-cb-preview="1">${area}</div></main>
        <footer>${after}</footer>
    </body></html>`, 'text/html');
}

function block(id, html) {
    return `<div class="cb-block" data-cb-block-id="${id}">${html}</div>`;
}

const checks = (result) => result.findings.map((f) => f.check);

describe('audit: headings', () => {
    it('finds nothing on a sound outline', () => {
        const result = audit(page(block(1, '<h2>Offer</h2>') + block(2, '<h3>Detail</h3>')));

        expect(result.findings).toEqual([]);
    });

    it('reports a page with no h1 at all, as a page finding', () => {
        const result = audit(page(block(1, '<h2>Offer</h2>'), { before: '' }));

        expect(result.findings).toEqual([expect.objectContaining({
            check: 'heading_h1_missing', severity: 'error', scope: 'page', blockId: null,
        })]);
    });

    it('counts the h1 of the host template', () => {
        const result = audit(page(block(1, '<h1>Second</h1>')));

        expect(result.findings).toEqual([expect.objectContaining({
            check: 'heading_h1_multiple', blockId: 1, excerpt: 'Second',
        })]);
    });

    it('reports a skipped level on the block that skips it', () => {
        const result = audit(page(block(4, '<h2>A</h2><h4>B</h4>')));

        expect(result.findings).toEqual([expect.objectContaining({
            check: 'heading_level_skipped', blockId: 4, data: { from: 2, to: 4 },
        })]);
    });

    it('leaves a skip inside the host template to the host', () => {
        const result = audit(page(block(1, '<h2>A</h2>'), { after: '<h4>Footer</h4>' }));

        expect(result.findings).toEqual([]);
    });

    it('allows going back up any number of levels', () => {
        const result = audit(page(block(1, '<h2>A</h2><h3>B</h3><h4>C</h4><h2>D</h2>')));

        expect(result.findings).toEqual([]);
    });

    it('reports an empty heading, but not one holding an image with alt', () => {
        const result = audit(page(block(1, '<h2> </h2>') + block(2, '<h2><img src="/logo.png" alt="Logo"></h2>')));

        expect(result.findings).toEqual([expect.objectContaining({ check: 'heading_empty', blockId: 1 })]);
    });

    it('returns the outline of the whole page, block ids included', () => {
        const { outline } = audit(page(block(3, '<h2>Offer</h2>')));

        expect(outline).toEqual([
            { level: 1, text: 'Page title', scope: 'page', blockId: null },
            { level: 2, text: 'Offer', scope: 'block', blockId: 3 },
        ]);
    });
});

describe('audit: images', () => {
    it('reports a missing alt as an error and an empty one as a warning', () => {
        const result = audit(page(
            block(1, '<img src="/uploads/a.png">')
            + block(2, '<img src="/uploads/team.jpg?w=800" alt="">'),
        ));

        expect(result.findings).toEqual([
            expect.objectContaining({ check: 'image_alt_missing', severity: 'error', blockId: 1, excerpt: 'a.png' }),
            expect.objectContaining({ check: 'image_alt_empty', severity: 'warning', blockId: 2, excerpt: 'team.jpg' }),
        ]);
    });

    it('trusts an image marked decorative on purpose', () => {
        const result = audit(page(
            block(1, '<img src="/a.png" alt="" role="presentation"><img src="/b.png" aria-hidden="true">'),
        ));

        expect(result.findings).toEqual([]);
    });

    it('ignores the images of the host template', () => {
        const result = audit(page(block(1, '<p>Hi</p>'), { before: '<h1>T</h1><img src="/logo.png">' }));

        expect(result.findings).toEqual([]);
    });
});

describe('audit: links', () => {
    const options = { genericLinkTexts: ['click here', 'en savoir plus'] };

    it('reports a link with no accessible name', () => {
        const result = audit(page(block(1, '<a href="/x"><svg></svg></a>')), options);

        expect(result.findings).toEqual([expect.objectContaining({
            check: 'link_text_empty', severity: 'error', blockId: 1, excerpt: '/x',
        })]);
    });

    it('reports vague text, whatever its case, accents and punctuation', () => {
        const result = audit(page(block(1, '<a href="/a">Click HERE!</a><a href="/b">En savoir plus →</a>')), options);

        expect(result.findings.map((f) => [f.check, f.data.text])).toEqual([
            ['link_text_generic', 'Click HERE!'],
            ['link_text_generic', 'En savoir plus →'],
        ]);
    });

    it('takes a label as the link name, so a vague text with a clear label passes', () => {
        const result = audit(page(block(1, '<a href="/a" aria-label="Our prices">click here</a>')), options);

        expect(result.findings).toEqual([]);
    });

    it('ignores an anchor without href', () => {
        const result = audit(page(block(1, '<a id="top"></a>')), options);

        expect(result.findings).toEqual([]);
    });
});

describe('audit: paragraphs', () => {
    it('reports a paragraph over the limit, with its count', () => {
        const words = Array.from({ length: 12 }, (_, i) => `w${i}`).join(' ');
        const result = audit(page(block(1, `<p>${words}</p><p>short one</p>`)), { paragraphMaxWords: 10 });

        expect(result.findings).toEqual([expect.objectContaining({
            check: 'paragraph_too_long', blockId: 1, data: { words: 12, max: 10 },
        })]);
    });

    it('a limit of 0 turns the check off', () => {
        const result = audit(page(block(1, `<p>${'word '.repeat(400)}</p>`)), { paragraphMaxWords: 0 });

        expect(result.findings).toEqual([]);
    });
});

describe('audit: options and placement', () => {
    it('leaves out a disabled check', () => {
        const result = audit(page(block(1, '<img src="/a.png">'), { before: '' }), {
            disabled: ['heading_h1_missing'],
        });

        expect(checks(result)).toEqual(['image_alt_missing']);
    });

    it('a finding in the area but outside any block has no block to open', () => {
        const result = audit(page('<section><img src="/a.png"></section>'));

        expect(result.findings).toEqual([expect.objectContaining({ scope: 'area', blockId: null })]);
    });

    it('audits nothing but headings on a page with no content area', () => {
        const doc = new DOMParser().parseFromString('<body><h1>T</h1><img src="/a.png"></body>', 'text/html');

        expect(audit(doc).findings).toEqual([]);
    });

    it('declares every check it can report', () => {
        expect(CHECKS).toHaveLength(9);
    });
});

describe('helpers', () => {
    it('normalize folds case, accents and punctuation', () => {
        expect(normalize('  Plus d’infos… ')).toBe('plus d infos');
        expect(normalize('Leer MÁS')).toBe('leer mas');
    });

    it('countWords counts runs of non-space', () => {
        expect(countWords('  one two\nthree  ')).toBe(3);
        expect(countWords('')).toBe(0);
    });

    it('accessibleName reads aria-labelledby, then text, then alt, then title', () => {
        const doc = page('<span id="l">Pricing</span>'
            + '<a id="a" href="#" aria-labelledby="l">x</a>'
            + '<a id="b" href="#"><span aria-hidden="true">→</span>Go</a>'
            + '<a id="c" href="#"><img alt="Home"></a>'
            + '<a id="d" href="#" title="Contact"></a>');
        const name = (id) => accessibleName(doc.getElementById(id), doc);

        expect([name('a'), name('b'), name('c'), name('d')]).toEqual(['Pricing', 'Go', 'Home', 'Contact']);
    });
});
