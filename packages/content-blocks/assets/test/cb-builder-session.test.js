import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * An expired session: the host's firewall redirects, `fetch()` follows, and
 * the builder must neither call it a save nor reload the preview onto the
 * login page. It says so, and picks the edit back up once the session is.
 */

function setupController() {
    document.body.innerHTML = `
        <div data-controller="cb-builder"
             data-cb-csrf-token="old-token"
             data-cb-api-base="/admin/cb">
            <span class="cb-shell__save-error" hidden></span>
            <span class="cb-shell__session-expired" hidden>
                <a class="cb-shell__session-login" href="#"></a>
            </span>
            <iframe></iframe>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const saveError = element.querySelector('.cb-shell__save-error');
    const banner = element.querySelector('.cb-shell__session-expired');
    const login = element.querySelector('.cb-shell__session-login');
    const iframe = element.querySelector('iframe');

    const controller = new Controller();
    const define = (name, value) => Object.defineProperty(controller, name, {
        value, configurable: true,
    });
    define('element', element);
    define('application', {
        getControllerForElementAndIdentifier: () => null,
    });
    define('areaIdValue', 42);
    define('sessionCheckAfterValue', 60000);
    define('hasSaveErrorTarget', true);
    define('saveErrorTarget', saveError);
    define('hasSessionExpiredTarget', true);
    define('sessionExpiredTarget', banner);
    define('hasSessionLoginTarget', true);
    define('sessionLoginTarget', login);
    define('hasIframeTarget', true);
    define('iframeTarget', iframe);
    define('hasProgressTarget', false);

    controller._sessionExpired = false;
    controller._lastActivityAt = Date.now();
    controller._lastSessionCheckAt = 0;

    return { controller, element, saveError, banner, login, iframe };
}

const redirected = () => ({
    ok: true, status: 200, redirected: true, url: '/login',
    headers: new Headers({ 'Content-Type': 'text/html' }),
    json: () => Promise.reject(new SyntaxError('HTML')),
});
const unauthorized = () => ({
    ok: false, status: 401, redirected: false,
    headers: new Headers({ 'X-Content-Blocks-Session': 'expired' }),
    json: () => Promise.resolve({ error: 'session_expired' }),
});
const state = (token) => ({
    ok: true, status: 200, redirected: false, headers: new Headers(),
    json: () => Promise.resolve({ hasUnpublishedChanges: true, csrfToken: token }),
});

describe('cb-builder session: telling a lost session apart', () => {
    it('reads a 401, the package marker and a followed redirect', () => {
        expect(Controller.isSessionLoss(unauthorized())).toBe(true);
        expect(Controller.isSessionLoss(redirected())).toBe(true);
        expect(Controller.isSessionLoss({
            ok: false, status: 403, headers: new Headers({ 'X-Content-Blocks-Session': 'expired' }),
        })).toBe(true);
    });

    it('leaves plain failures and successes alone', () => {
        expect(Controller.isSessionLoss(null)).toBe(false);
        expect(Controller.isSessionLoss({ ok: false, status: 403, headers: new Headers() })).toBe(false);
        expect(Controller.isSessionLoss({ ok: false, status: 500, headers: new Headers() })).toBe(false);
        expect(Controller.isSessionLoss(state('t'))).toBe(false);
    });
});

describe('cb-builder session: what a lost session does', () => {
    let ctx;
    let errorSpy;

    beforeEach(() => {
        ctx = setupController();
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        errorSpy.mockRestore();
        vi.unstubAllGlobals();
    });

    it('a mutation answered by the login page returns null and shows the banner', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(redirected()));

        const result = await ctx.controller._performJsonRequest('POST', '/admin/cb/x', {});

        expect(result).toBeNull();
        expect(ctx.banner.hidden).toBe(false);
        expect(ctx.saveError.hidden).toBe(true);
    });

    it('the banner link reopens this page, which the firewall returns to', () => {
        ctx.controller._onSessionExpired();

        expect(ctx.login.href).toBe(window.location.href);
    });

    it('a section save reporting a lost session never reloads the preview', async () => {
        const loading = vi.spyOn(ctx.controller, '_beginLoading');
        const listen = vi.spyOn(ctx.iframe, 'addEventListener');
        ctx.controller._onSaveError({ detail: { sessionExpired: true } });

        ctx.controller.reload();

        expect(loading).not.toHaveBeenCalled();
        expect(listen).not.toHaveBeenCalled();
        expect(ctx.banner.hidden).toBe(false);
    });

    it('a failed hot reload with a lost session does not fall back to a reload', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(unauthorized()));
        const reload = vi.spyOn(ctx.controller, 'reload');

        await ctx.controller._refreshBlock(7);
        await ctx.controller._refreshSection(3);

        expect(reload).not.toHaveBeenCalled();
        expect(ctx.banner.hidden).toBe(false);
    });

    it('the generic save error stays hidden while the session banner is up', () => {
        ctx.controller._onSessionExpired();

        ctx.controller._onSaveError(new CustomEvent('cb:save:error'));

        expect(ctx.saveError.hidden).toBe(true);
    });

    it('a Live Component answered by the login page raises the session banner', () => {
        const handlers = {};
        const component = {
            element: document.createElement('div'),
            on: (name, fn) => { handlers[name] = fn; },
        };
        ctx.controller._onLiveConnect({ detail: { component } });
        const controls = { displayError: true };

        handlers['response:error']({ response: redirected() }, controls);

        expect(controls.displayError).toBe(false);
        expect(ctx.banner.hidden).toBe(false);
        expect(ctx.saveError.hidden).toBe(true);
    });
});

describe('cb-builder session: checking on the way back', () => {
    let ctx;

    beforeEach(() => {
        ctx = setupController();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('an interaction right after another asks nothing', () => {
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        ctx.controller._onActivity();

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('the first interaction after a long idle checks the session', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(unauthorized());
        vi.stubGlobal('fetch', fetchSpy);
        ctx.controller._lastActivityAt = Date.now() - 61000;

        ctx.controller._onActivity();
        await ctx.controller._sessionCheck;

        expect(fetchSpy).toHaveBeenCalledWith('/admin/cb/area/42/state', expect.anything());
        expect(ctx.banner.hidden).toBe(false);
    });

    it('a live session hands its CSRF token over', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(state('new-token')));

        await ctx.controller._checkSession();

        expect(ctx.element.dataset.cbCsrfToken).toBe('new-token');
        expect(ctx.banner.hidden).toBe(true);
    });

    it('a session found back hides the banner, resends the edit, reloads', async () => {
        const flush = vi.fn().mockReturnValue(false);
        const host = document.createElement('div');
        host.setAttribute('data-controller', 'cb-autosave');
        const content = document.createElement('div');
        content.appendChild(host);
        Object.defineProperty(ctx.controller, 'hasSidebarContentTarget', { value: true });
        Object.defineProperty(ctx.controller, 'sidebarContentTarget', { value: content });
        Object.defineProperty(ctx.controller, 'application', {
            value: { getControllerForElementAndIdentifier: () => ({ flush }) },
        });
        const reload = vi.spyOn(ctx.controller, 'reload').mockImplementation(() => {});
        ctx.controller._onSessionExpired();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(state('new-token')));

        await ctx.controller._checkSession();

        expect(ctx.banner.hidden).toBe(true);
        expect(ctx.controller._sessionExpired).toBe(false);
        expect(flush).toHaveBeenCalled();
        expect(reload).toHaveBeenCalled();
        expect(ctx.element.dataset.cbCsrfToken).toBe('new-token');
    });

    it('while lost, interactions recheck but never back to back', async () => {
        const fetchSpy = vi.fn().mockResolvedValue(unauthorized());
        vi.stubGlobal('fetch', fetchSpy);
        ctx.controller._onSessionExpired();

        ctx.controller._onActivity();
        await ctx.controller._sessionCheck;
        ctx.controller._onActivity();

        expect(fetchSpy).toHaveBeenCalledTimes(1);
    });

    it('a network failure says nothing about the session', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')));

        await ctx.controller._checkSession();

        expect(ctx.banner.hidden).toBe(true);
    });

    it('a closed builder dialog asks nothing', async () => {
        const dialog = document.createElement('dialog');
        document.body.appendChild(dialog);
        dialog.appendChild(ctx.element);
        const fetchSpy = vi.fn();
        vi.stubGlobal('fetch', fetchSpy);

        await ctx.controller._checkSession();

        expect(fetchSpy).not.toHaveBeenCalled();
    });
});
