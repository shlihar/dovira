/**
 * Global phone mask.
 *
 * Applies a Ukrainian phone mask (+38 (0XX) XXX-XX-XX) to every
 * `[data-phone-mask]` input on the page — including ones injected later by
 * Livewire re-renders or JS repeaters. IMask is loaded once, on demand, from
 * the local vendor bundle (no CDN).
 */
(() => {
    const MASK = '+{38} (000) 000-00-00';
    const SELECTOR = '[data-phone-mask]';

    let imaskPromise = null;

    const loadImask = () => {
        if (window.IMask) return Promise.resolve(window.IMask);
        if (imaskPromise) return imaskPromise;

        const existing = document.querySelector('script[data-imask-loader]');
        if (existing) {
            imaskPromise = new Promise((resolve, reject) => {
                existing.addEventListener('load', () => resolve(window.IMask), { once: true });
                existing.addEventListener('error', reject, { once: true });
            });
            return imaskPromise;
        }

        imaskPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/static/vendor/imask.min.js';
            script.async = true;
            script.dataset.imaskLoader = 'true';
            script.onload = () => resolve(window.IMask);
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return imaskPromise;
    };

    const applyTo = (input) => {
        if (!(input instanceof HTMLInputElement)) return;
        if (input.dataset.phoneMaskBound === 'true') return;
        input.dataset.phoneMaskBound = 'true';
        input.setAttribute('inputmode', 'tel');
        loadImask()
            .then((IMask) => {
                if (!IMask) return;
                IMask(input, { mask: MASK, lazy: true, overwrite: true });
            })
            .catch(() => { delete input.dataset.phoneMaskBound; });
    };

    const scan = (root = document) => {
        if (root instanceof HTMLElement && root.matches?.(SELECTOR)) applyTo(root);
        root.querySelectorAll?.(SELECTOR).forEach(applyTo);
    };

    const start = () => {
        scan();
        // Catch inputs added later (Livewire morphs, repeater rows, wizards).
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1) scan(node);
                });
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
