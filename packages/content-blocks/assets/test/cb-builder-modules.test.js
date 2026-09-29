// @vitest-environment node
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import Controller from '../controllers/cb-builder_controller.js';
import { mix } from '../builder/mix.js';

const templates = fileURLToPath(new URL('../../templates/builder/', import.meta.url));

describe('mix', () => {
    it('copies methods, accessors and statics onto the target', () => {
        class Part {
            static LIMIT = 3;
            static double(n) { return n * 2; }
            get label() { return `#${this.id}`; }
            greet() { return `hi ${this.label}`; }
        }
        class Target {
            constructor() { this.id = 7; }
        }
        mix(Target, Part);

        const target = new Target();
        expect(target.greet()).toBe('hi #7');
        expect(Target.LIMIT).toBe(3);
        expect(Target.double(2)).toBe(4);
        // Copied as an accessor, not evaluated once on the prototype.
        target.id = 8;
        expect(target.label).toBe('#8');
    });

    it('refuses a name two parts define', () => {
        class A { run() {} }
        class B { run() {} }

        expect(() => mix(class {}, A, B)).toThrow('"run" is defined twice (B)');
    });

    it('refuses a part overriding the target itself', () => {
        class Target { connect() {} }
        class Part { connect() {} }

        expect(() => mix(Target, Part)).toThrow('"connect" is defined twice');
    });

    it('refuses a static defined twice', () => {
        class A { static KEY = 'a'; }
        class B { static KEY = 'b'; }

        expect(() => mix(class {}, A, B)).toThrow('"KEY" is defined twice');
    });
});

describe('cb-builder assembled from builder/', () => {
    // An action whose method went missing in a move fails only on click.
    it('has a method for every cb-builder action in the templates', () => {
        const actions = new Set();
        for (const file of readdirSync(templates)) {
            if (!file.endsWith('.twig')) continue;
            const source = readFileSync(join(templates, file), 'utf8');
            for (const [, method] of source.matchAll(/cb-builder#(\w+)/g)) {
                actions.add(method);
            }
        }

        expect(actions.size).toBeGreaterThan(10);
        for (const method of actions) {
            expect(typeof Controller.prototype[method], method).toBe('function');
        }
    });

    it('keeps the statics other code reads off the controller', () => {
        expect(typeof Controller.isSessionLoss).toBe('function');
        expect(typeof Controller.shortcutIntent).toBe('function');
        expect(typeof Controller.trapTab).toBe('function');
        expect(Controller.targets).toContain('iframe');
        expect(Controller.values.areaId).toBe(Number);
    });

    it('reads the API base through an accessor', () => {
        const element = { dataset: { cbApiBase: '/admin/cb' } };
        const controller = Object.create(Controller.prototype);
        Object.defineProperty(controller, 'element', { value: element });

        expect(controller._apiBase).toBe('/admin/cb');
        delete element.dataset.cbApiBase;
        expect(controller._apiBase).toBe(Controller.DEFAULT_API_BASE);
    });
});
