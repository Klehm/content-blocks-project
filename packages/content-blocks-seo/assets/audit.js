/**
 * The on-page audit: pure checks on a rendered page, no network, no builder.
 * @see docs/internals/seo.md#what-the-linter-reads
 */

export const CHECKS = [
    'heading_h1_missing',
    'heading_h1_multiple',
    'heading_level_skipped',
    'heading_empty',
    'image_alt_missing',
    'image_alt_empty',
    'link_text_empty',
    'link_text_generic',
    'paragraph_too_long',
];

const SEVERITY = {
    heading_h1_missing: 'error',
    heading_h1_multiple: 'warning',
    heading_level_skipped: 'warning',
    heading_empty: 'error',
    image_alt_missing: 'error',
    image_alt_empty: 'warning',
    link_text_empty: 'error',
    link_text_generic: 'warning',
    paragraph_too_long: 'warning',
};

const HEADINGS = 'h1, h2, h3, h4, h5, h6';
const EXCERPT_LENGTH = 60;

/**
 * @param {Document} doc the page rendered with `cb_preview=1&cb_chrome=0`
 * @param {{genericLinkTexts?: string[], paragraphMaxWords?: number,
 *          disabled?: string[]}} options
 * @returns {{findings: object[], outline: object[]}}
 */
export function audit(doc, options = {}) {
    const areas = [...doc.querySelectorAll('.cb-content-area[data-cb-preview]')];
    const disabled = new Set(options.disabled ?? []);
    const findings = [];
    const report = (check, element, data = {}) => {
        if (disabled.has(check)) return;
        findings.push({
            check,
            severity: SEVERITY[check],
            ...locate(element, areas),
            excerpt: element ? excerpt(element) : '',
            data,
        });
    };

    const outline = auditHeadings(doc, areas, report);

    for (const element of inAreas(areas, 'img')) {
        if (isDecorative(element)) continue;
        if (!element.hasAttribute('alt')) report('image_alt_missing', element);
        else if (element.getAttribute('alt').trim() === '') report('image_alt_empty', element);
    }

    const generic = new Set((options.genericLinkTexts ?? []).map(normalize));
    for (const element of inAreas(areas, 'a[href]')) {
        const name = accessibleName(element, doc);
        if (name === '') report('link_text_empty', element);
        else if (generic.has(normalize(name))) report('link_text_generic', element, { text: name });
    }

    const maxWords = options.paragraphMaxWords ?? 150;
    if (maxWords > 0) {
        for (const element of inAreas(areas, 'p')) {
            const words = countWords(element.textContent);
            if (words > maxWords) report('paragraph_too_long', element, { words, max: maxWords });
        }
    }

    return { findings, outline };
}

/**
 * The whole page counts for the outline: the host's own `h1` is usually in
 * its template. Only a level skipped inside the area is the editor's to fix.
 */
function auditHeadings(doc, areas, report) {
    const headings = [...doc.body.querySelectorAll(HEADINGS)];
    const outline = [];
    let previous = null;
    let h1Seen = false;

    for (const element of headings) {
        const level = Number(element.tagName[1]);
        const text = squash(element.textContent) || imageAlts(element);
        const place = locate(element, areas);
        outline.push({ level, text, ...place });

        if (text === '') report('heading_empty', element);
        if (level === 1) {
            if (h1Seen) report('heading_h1_multiple', element);
            h1Seen = true;
        }
        if (previous !== null && level > previous + 1 && place.scope === 'block') {
            report('heading_level_skipped', element, { from: previous, to: level });
        }
        previous = level;
    }

    if (!h1Seen) report('heading_h1_missing', null);

    return outline;
}

/** Which block, if any, the editor opens to fix it. */
function locate(element, areas) {
    if (!element) return { scope: 'page', blockId: null };
    if (!areas.some((area) => area.contains(element))) return { scope: 'page', blockId: null };
    const block = element.closest('[data-cb-block-id]');
    const id = block ? Number(block.getAttribute('data-cb-block-id')) : NaN;

    return Number.isInteger(id) && id > 0
        ? { scope: 'block', blockId: id }
        : { scope: 'area', blockId: null };
}

function inAreas(areas, selector) {
    return areas.flatMap((area) => [...area.querySelectorAll(selector)]);
}

/** `alt=""` with an explicit role says decorative on purpose. */
function isDecorative(img) {
    if (img.getAttribute('aria-hidden') === 'true') return true;
    const role = img.getAttribute('role');

    return role === 'presentation' || role === 'none';
}

/** A subset of the accessible name computation: what a link's content gives. */
export function accessibleName(element, doc) {
    const label = element.getAttribute('aria-label');
    if (label && label.trim() !== '') return squash(label);

    const labelledBy = element.getAttribute('aria-labelledby');
    if (labelledBy) {
        const text = labelledBy.split(/\s+/)
            .map((id) => doc.getElementById(id)?.textContent ?? '')
            .join(' ');
        if (squash(text) !== '') return squash(text);
    }

    const own = squash(visibleText(element));
    if (own !== '') return own;

    const alts = imageAlts(element);
    if (alts !== '') return alts;

    return squash(element.getAttribute('title') ?? '');
}

function visibleText(element) {
    let text = '';
    for (const node of element.childNodes) {
        if (node.nodeType === 3) text += node.textContent;
        else if (node.nodeType === 1 && node.getAttribute('aria-hidden') !== 'true') {
            text += ' ' + visibleText(node);
        }
    }

    return text;
}

function imageAlts(element) {
    return squash([...element.querySelectorAll('img[alt]')].map((img) => img.getAttribute('alt')).join(' '));
}

/** Case, accents and punctuation aside: "Cliquez ici !" is "cliquez ici". */
export function normalize(text) {
    return squash(String(text)
        .normalize('NFKD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\s]/gu, ' '));
}

export function countWords(text) {
    return (String(text).match(/\S+/g) ?? []).length;
}

function squash(text) {
    return String(text).replace(/\s+/g, ' ').trim();
}

function excerpt(element) {
    let text = element.tagName === 'IMG'
        ? fileName(element.getAttribute('src') ?? '')
        : squash(element.textContent) || imageAlts(element);
    if (text === '' && element.tagName === 'A') text = element.getAttribute('href') ?? '';

    return text.length > EXCERPT_LENGTH ? text.slice(0, EXCERPT_LENGTH - 1) + '…' : text;
}

function fileName(src) {
    const path = src.split(/[?#]/)[0];

    return path.slice(path.lastIndexOf('/') + 1) || src;
}
