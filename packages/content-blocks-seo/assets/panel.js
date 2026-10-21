/**
 * The SEO panel in the builder shell: audits the draft page, lists what it
 * found, and selects the block at fault through the core's `cb:focus`.
 * @see docs/internals/seo.md#the-panel-reads-the-page-a-crawler-reads
 */

export const ACTION_KEY = 'content-blocks-seo.audit';
const RERUN_ON = ['cb:ready', 'cb:block:saved', 'cb:section:saved', 'cb:area:changed'];
const RERUN_DELAY = 600;

export class SeoPanel {
    constructor(root, { fetch = window.fetch.bind(window), audit = null, delay = RERUN_DELAY } = {}) {
        this.root = root;
        this.fetch = fetch;
        this.audit = audit;
        this.delay = delay;
        this.shell = root.closest('[data-cb-csrf-token]') ?? root.parentElement;
        this.strings = readJson(root.dataset.i18n);
        this.options = readJson(root.dataset.options);
        this.locale = root.dataset.locale || document.documentElement.lang || 'en';
        this.list = root.querySelector('[data-cb-seo-findings]');
        this.outline = root.querySelector('[data-cb-seo-outline]');
        this.status = root.querySelector('[data-cb-seo-status]');
        this.run = 0;
        this.timer = null;
        this.schedule = this.schedule.bind(this);

        root.querySelector('[data-cb-seo-close]')?.addEventListener('click', () => this.close());
        root.querySelector('[data-cb-seo-rerun]')?.addEventListener('click', () => this.refresh());
        this.list.addEventListener('click', (event) => this.onFindingClick(event));
    }

    get isOpen() {
        return !this.root.hidden;
    }

    toggle() {
        if (this.isOpen) this.close();
        else this.open();
    }

    open() {
        this.root.hidden = false;
        for (const type of RERUN_ON) this.shell.addEventListener(type, this.schedule);

        return this.refresh();
    }

    close() {
        this.root.hidden = true;
        clearTimeout(this.timer);
        this.run++;
        for (const type of RERUN_ON) this.shell.removeEventListener(type, this.schedule);
    }

    /** A save lands in bursts while typing: one audit once it settles. */
    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), this.delay);
    }

    async refresh() {
        const run = ++this.run;
        this.root.setAttribute('aria-busy', 'true');
        this.say(this.strings.running);

        let result;
        try {
            const [doc, check] = await Promise.all([this.load(), this.auditor()]);
            result = check(doc, this.options);
        } catch (e) {
            result = null;
        }
        // A newer audit started while this one was on its way.
        if (run !== this.run) return;

        this.root.removeAttribute('aria-busy');
        if (result === null) {
            this.list.replaceChildren();
            this.outline.replaceChildren();
            this.say(this.strings.failed);
            return;
        }
        this.render(result);
    }

    /** Served by its own versioned URL, so a route needs no relative import. */
    async auditor() {
        if (this.audit === null) {
            this.audit = (await import(this.root.dataset.auditUrl)).audit;
        }

        return this.audit;
    }

    async load() {
        const url = new URL(this.root.dataset.previewUrl, window.location.href);
        url.searchParams.set('cb_chrome', '0');
        const response = await this.fetch(url.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'text/html' },
        });
        // A login page is not the page under audit.
        if (!response.ok || response.redirected) throw new Error(`HTTP ${response.status}`);

        return new DOMParser().parseFromString(await response.text(), 'text/html');
    }

    render({ findings, outline }) {
        const errors = findings.filter((f) => f.severity === 'error').length;
        const warnings = findings.length - errors;
        this.say(findings.length === 0
            ? this.strings.summary_none
            : [this.count('errors', errors), this.count('warnings', warnings)].filter(Boolean).join(' · '));

        const order = { error: 0, warning: 1 };
        const sorted = [...findings].sort((a, b) => order[a.severity] - order[b.severity]);
        this.list.replaceChildren(...sorted.map((finding) => this.renderFinding(finding)));
        this.outline.replaceChildren(...outline.map((heading) => this.renderHeading(heading)));
    }

    renderFinding(finding) {
        const item = document.createElement('li');
        const body = document.createElement(finding.blockId ? 'button' : 'div');
        body.className = `cb-seo__finding cb-seo__finding--${finding.severity}`;
        body.dataset.check = finding.check;
        if (finding.blockId) {
            body.type = 'button';
            body.dataset.blockId = String(finding.blockId);
        }

        body.append(
            line('cb-seo__message', this.format(`check_${finding.check}`, finding.data)),
            line('cb-seo__hint', this.format(`hint_${finding.check}`, hintData(finding))),
        );
        const where = finding.scope === 'block' ? finding.excerpt
            : [this.strings[`scope_${finding.scope}`], finding.excerpt].filter(Boolean).join(' — ');
        if (where) body.append(line('cb-seo__where', where));
        item.append(body);

        return item;
    }

    renderHeading({ level, text, blockId }) {
        const item = document.createElement('li');
        item.className = `cb-seo__heading cb-seo__heading--h${level}`;
        item.style.setProperty('--cb-seo-depth', String(level - 1));
        const tag = line('cb-seo__tag', `h${level}`);
        const label = line('cb-seo__heading-text', text || this.strings.outline_untitled);
        if (blockId === null) label.classList.add('cb-seo__heading-text--page');
        item.append(tag, label);

        return item;
    }

    onFindingClick(event) {
        const button = event.target.closest('[data-block-id]');
        if (!button) return;
        this.root.dispatchEvent(new CustomEvent('cb:focus', {
            bubbles: true,
            detail: { kind: 'block', id: Number(button.dataset.blockId) },
        }));
    }

    count(kind, n) {
        if (n === 0) return '';
        const form = new Intl.PluralRules(this.locale).select(n) === 'one' ? 'one' : 'other';

        return this.format(`${kind}_${form}`, { count: n });
    }

    format(key, data = {}) {
        return String(this.strings[key] ?? key)
            .replace(/\{(\w+)\}/g, (match, name) => (name in data ? String(data[name]) : match));
    }

    say(text) {
        this.status.textContent = text ?? '';
    }
}

function hintData(finding) {
    return { ...finding.data, next: (finding.data.from ?? 0) + 1 };
}

function line(className, text) {
    const span = document.createElement('span');
    span.className = className;
    span.textContent = text;

    return span;
}

function readJson(value) {
    try {
        return JSON.parse(value ?? '{}') ?? {};
    } catch (e) {
        return {};
    }
}

const panels = new WeakMap();

export function panelFor(root) {
    if (!panels.has(root)) panels.set(root, new SeoPanel(root));

    return panels.get(root);
}

/** Mounted on first use: the shell may be rendered after this module ran. */
export function onBuilderAction(event) {
    if (event.detail?.key !== ACTION_KEY) return;
    const shell = event.target.closest?.('[data-cb-csrf-token]') ?? event.target;
    const root = shell.querySelector?.('[data-cb-seo]');
    if (root) panelFor(root).toggle();
}

if (!window.__cbSeoListening) {
    window.__cbSeoListening = true;
    document.addEventListener('cb:builder:action', onBuilderAction);
}
