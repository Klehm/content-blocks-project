/**
 * Shared plumbing for the rich-text controllers: editor-agnostic and
 * side-effect free on import, so it is testable without a real editor.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
 */

// Module-level cache so several rich-text blocks on one page share a single
// network load per URL instead of racing to fetch the same script.
const scriptLoaders = new Map();

/**
 * Load a script once per URL. Resolves when it has executed. With an SRI hash
 * the browser refuses a file whose bytes changed on the CDN.
 */
export function loadScript(url, integrity = '') {
    if (scriptLoaders.has(url)) return scriptLoaders.get(url);

    const loader = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.referrerPolicy = 'origin';
        if (integrity) {
            script.integrity = integrity;
            script.crossOrigin = 'anonymous';
        }
        script.onload = () => resolve();
        script.onerror = () => {
            // Drop the rejected promise so a later connect can retry rather
            // than inheriting this failure forever.
            scriptLoaders.delete(url);
            reject(new Error(`Failed to load editor script: ${url}`));
        };
        document.head.appendChild(script);
    });

    scriptLoaders.set(url, loader);

    return loader;
}

/**
 * Add a stylesheet once per URL. Fire-and-forget: a missing stylesheet
 * degrades the editor's looks, it does not stop it from working.
 */
export function loadStylesheet(url, integrity = '') {
    if (!url) return;

    // Attribute by attribute, not through a selector: a URL with a quote
    // would break it, and CSS.escape is not universally available.
    const existing = [...document.querySelectorAll('link[rel="stylesheet"]')];
    if (existing.some((link) => link.getAttribute('href') === url)) return;

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    if (integrity) {
        link.integrity = integrity;
        link.crossOrigin = 'anonymous';
    }
    document.head.appendChild(link);
}

/**
 * An empty `scriptUrl` is the `cdn: false` contract — the host bundled the
 * editor. Saying so beats a TypeError three frames deeper.
 */
export async function resolveEditorGlobal(globalName, scriptUrl, integrity = '') {
    if (window[globalName]) return window[globalName];

    if (!scriptUrl) {
        throw new Error(
            `window.${globalName} is not defined and CDN loading is disabled `
            + `(content_blocks_kit.blocks.rich_text.options.cdn: false), so the host is `
            + `expected to bundle the editor and expose it globally.`,
        );
    }

    await loadScript(scriptUrl, integrity);

    if (!window[globalName]) {
        throw new Error(`Loaded ${scriptUrl} but window.${globalName} is still undefined.`);
    }

    return window[globalName];
}

/**
 * Parse a JSON `data-*-value`, falling back rather than throwing — a
 * malformed host config should cost its own effect, not the editor.
 */
export function parseJsonValue(raw, fallback) {
    if (!raw) return fallback;
    try {
        const parsed = JSON.parse(raw);
        return parsed === null ? fallback : parsed;
    } catch (_) {
        console.warn('[cb-rich-text] ignoring malformed JSON value:', raw);
        return fallback;
    }
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Objects merge key by key; arrays and scalars are replaced, because a
 * spelled-out toolbar is a replacement rather than an append.
 */
export function mergeConfig(base, override) {
    if (!isPlainObject(override)) return base;

    const out = { ...base };
    for (const [key, value] of Object.entries(override)) {
        out[key] = isPlainObject(value) && isPlainObject(base[key])
            ? mergeConfig(base[key], value)
            : value;
    }

    return out;
}

/**
 * The builder's CSRF token, read off the nearest ancestor carrying it — the
 * same lookup `cb-file-upload` does.
 */
export function readCsrfToken(element) {
    return element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
}

/** The area the builder edits: the upload endpoint checks rights on it. */
export function readAreaId(element) {
    return element.closest('[data-cb-area-id]')?.dataset.cbAreaId || '';
}

/**
 * Same contract as `cb-file-upload`, and shared by both editors — so an image
 * dropped in either lands in the same storage through the same validation.
 */
export async function uploadFile(file, { uploadUrl, csrfToken, areaId, filename }) {
    if (!uploadUrl) throw new Error('Uploads are disabled for this block.');

    const formData = new FormData();
    formData.append('file', file, filename || file.name || 'upload');
    formData.append('area', areaId || '');

    let response;
    try {
        response = await fetch(uploadUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });
    } catch (e) {
        throw new Error('Upload failed: network error');
    }

    let payload = {};
    try {
        payload = await response.json();
    } catch (_) {
        // Non-JSON body (a proxy error page, say) — fall through to the
        // status-based message below.
    }

    if (!response.ok || !payload.url) {
        throw new Error(payload.error || `Upload failed (HTTP ${response.status})`);
    }

    return payload.url;
}

/**
 * `showModal()` puts the dialog in the top layer, so anything left on `<body>`
 * renders behind it. No-ops outside a dialog, since a host may embed anywhere.
 *
 * @returns {{sweep: Function, stop: Function}} sweep() adopts what is already
 *          there; stop() tears the observer down
 */
export function adoptDetachedUi(element, selector) {
    const dialog = element.closest('dialog');
    if (!dialog) return { sweep: () => {}, stop: () => {} };

    const adopt = (node) => {
        if (node.nodeType === 1 && node.matches?.(selector)) dialog.appendChild(node);
    };

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) adopt(node);
        }
    });
    observer.observe(document.body, { childList: true });

    const sweep = () => document.body.querySelectorAll(`:scope > ${selector}`).forEach(adopt);
    sweep();

    return { sweep, stop: () => observer.disconnect() };
}
