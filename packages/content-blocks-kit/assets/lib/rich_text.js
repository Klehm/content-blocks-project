/**
 * Shared plumbing for the rich-text editor controllers (cb-tinymce,
 * cb-ckeditor, and any adapter a host adds).
 *
 * Everything here is editor-agnostic and side-effect free on import, so it is
 * unit-testable without a real editor: asset loading, config merging, the
 * upload bridge to the builder's endpoint, and the one piece of hard-won
 * builder knowledge both editors need — re-parenting the UI they append to
 * <body> into the builder's modal <dialog>.
 */

// Module-level cache so several rich-text blocks on one page share a single
// network load per URL instead of racing to fetch the same script.
const scriptLoaders = new Map();

/**
 * Load a script once per URL. Resolves when it has executed.
 */
export function loadScript(url) {
    if (scriptLoaders.has(url)) return scriptLoaders.get(url);

    const loader = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.referrerPolicy = 'origin';
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
export function loadStylesheet(url) {
    if (!url) return;

    // Compared attribute by attribute rather than through a selector: a URL
    // with a quote or a bracket in it would break the selector, and CSS.escape
    // is not universally available.
    const existing = [...document.querySelectorAll('link[rel="stylesheet"]')];
    if (existing.some((link) => link.getAttribute('href') === url)) return;

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    document.head.appendChild(link);
}

/**
 * Resolve the editor's global, loading its script first when the kit is in
 * charge of that.
 *
 * An empty `scriptUrl` is the `cdn: false` contract: the host bundles the
 * editor, so the global must already be there. Saying so explicitly is worth
 * more than a TypeError three frames deeper.
 */
export async function resolveEditorGlobal(globalName, scriptUrl) {
    if (window[globalName]) return window[globalName];

    if (!scriptUrl) {
        throw new Error(
            `window.${globalName} is not defined and CDN loading is disabled `
            + `(content_blocks_kit.blocks.rich_text.options.cdn: false), so the host is `
            + `expected to bundle the editor and expose it globally.`,
        );
    }

    await loadScript(scriptUrl);

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
 * Merge the host's `options.config` over an adapter's coded init config.
 *
 * Plain objects merge key by key so overriding `image.toolbar` does not wipe
 * `image.styles`; arrays and scalars are replaced wholesale, because a
 * toolbar the host spells out is a replacement, never an append.
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

/**
 * POST a file to the builder's upload endpoint and resolve its public URL.
 *
 * Same contract as `cb-file-upload`: a `file` part, the CSRF header, and a
 * `{ url }` / `{ error }` JSON answer. Both editors get their media handling
 * from this one function, so an image dropped into TinyMCE and one dropped
 * into CKEditor land in the same storage through the same validation.
 */
export async function uploadFile(file, { uploadUrl, csrfToken, filename }) {
    if (!uploadUrl) throw new Error('Uploads are disabled for this block.');

    const formData = new FormData();
    formData.append('file', file, filename || file.name || 'upload');

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
 * Move UI an editor appends to <body> into the builder's <dialog>.
 *
 * The builder opens with `showModal()`, which puts the dialog in the browser's
 * top layer: anything left on <body> renders behind it and cannot be clicked.
 * Toolbars, dropdown panels and modals therefore have to be re-parented — for
 * TinyMCE that is `.tox-tinymce-aux`, for CKEditor `.ck-body-wrapper`.
 *
 * Returns `{ sweep, stop }`: `sweep()` adopts whatever is already on <body>
 * (call it once after the editor is up, for containers created before the
 * observer saw them), `stop()` tears the observer down. Both are no-ops when
 * the field is not inside a dialog — a host may embed the form anywhere.
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
