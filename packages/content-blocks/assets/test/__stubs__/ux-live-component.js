/**
 * Vitest stub for `@symfony/ux-live-component`.
 *
 * The real module is provided at runtime by the sandboxes' AssetMapper
 * importmap (it maps the bare specifier to the bundle's dist file), but it
 * isn't an npm dependency, so vitest can't resolve it. The vitest config
 * aliases the specifier to this stub. Tests inject a fake component via
 * `__setMockComponent` and assert on its `action` spy.
 */

let mockComponent = null;

export function __setMockComponent(component) {
    mockComponent = component;
}

export function getComponent() {
    return Promise.resolve(mockComponent);
}
