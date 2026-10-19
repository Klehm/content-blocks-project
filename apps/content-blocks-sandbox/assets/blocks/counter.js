// Linked only on a page rendering an animated counter block. Declared an
// entrypoint in importmap.php, so the page's importmap maps this import.
import { easeOut } from './counter-easing.js';

// Runs on the counters present at load, and on a block the builder swaps in
// (`cb:block:rendered`), since a module loaded later misses no counter.
const DURATION = 800;

function run(el) {
    if (el.dataset.demoCounterDone) return;
    el.dataset.demoCounterDone = '1';
    const target = Number(el.dataset.demoCounter) || 0;
    const value = el.querySelector('.demo-counter__value');
    const start = performance.now();
    const step = (now) => {
        const t = Math.min(1, (now - start) / DURATION);
        value.textContent = String(Math.round(target * easeOut(t)));
        if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
}

const runAll = (root) => root.querySelectorAll('[data-demo-counter]').forEach(run);

runAll(document);
document.addEventListener('cb:block:rendered', (event) => runAll(event.target));
