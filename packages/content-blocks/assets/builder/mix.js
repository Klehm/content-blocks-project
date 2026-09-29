/**
 * Copies each part's methods, accessors and statics onto the controller, so
 * `this` in a part is the controller. A name defined twice throws.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
export function mix(target, ...parts) {
    for (const part of parts) {
        copy(part.prototype, target.prototype, ['constructor'], part.name);
        copy(part, target, ['length', 'name', 'prototype'], part.name);
    }

    return target;
}

function copy(from, to, skip, origin) {
    for (const key of Reflect.ownKeys(from)) {
        if (skip.includes(key)) continue;
        if (Object.prototype.hasOwnProperty.call(to, key)) {
            throw new Error(`[cb-builder] "${String(key)}" is defined twice (${origin})`);
        }
        Object.defineProperty(to, key, Object.getOwnPropertyDescriptor(from, key));
    }
}
