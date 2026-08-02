import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

(() => {
    const overlay = document.querySelector('[data-page-skeleton]');
    if (!overlay) return;

    const SHOW_DELAY_MS = 90;
    const HIDE_DELAY_MS = 220;
    let showTimer = null;
    let hideTimer = null;

    const clearTimers = () => {
        if (showTimer) {
            window.clearTimeout(showTimer);
            showTimer = null;
        }
        if (hideTimer) {
            window.clearTimeout(hideTimer);
            hideTimer = null;
        }
    };

    const show = () => {
        clearTimers();
        showTimer = window.setTimeout(() => {
            overlay.hidden = false;
            requestAnimationFrame(() => {
                overlay.classList.add('is-visible');
            });
        }, SHOW_DELAY_MS);
    };

    const hide = () => {
        clearTimers();
        overlay.classList.remove('is-visible');
        hideTimer = window.setTimeout(() => {
            overlay.hidden = true;
        }, HIDE_DELAY_MS);
    };

    const isInternalNavigableLink = (anchor) => {
        if (!anchor) return false;
        const href = anchor.getAttribute('href') || '';
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return false;
        if (anchor.hasAttribute('download') || anchor.target === '_blank') return false;

        const url = new URL(href, window.location.origin);
        if (url.origin !== window.location.origin) return false;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return false;

        return true;
    };

    document.addEventListener('click', (event) => {
        const anchor = event.target.closest('a[href]');
        if (!anchor) return;
        if (event.defaultPrevented) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (!isInternalNavigableLink(anchor)) return;
        if (anchor.hasAttribute('data-no-page-skeleton')) return;

        show();
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form');
        if (!form) return;
        if (event.defaultPrevented) return;
        if (form.hasAttribute('data-no-page-skeleton')) return;

        show();
    }, true);

    window.addEventListener('pageshow', hide);
    window.addEventListener('load', hide);
})();

console.log('%c Dovira', 'color: #1a9a5a; font-size: 24px; font-weight: bold;');
