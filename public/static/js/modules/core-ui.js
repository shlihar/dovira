/* App-like mobile: disable zoom. iOS Safari ignores user-scalable=no, so we
   also block its pinch gesture events and any multi-touch pinch. */
(() => {
    const blockGesture = (event) => event.preventDefault();
    ['gesturestart', 'gesturechange', 'gestureend'].forEach((type) => {
        document.addEventListener(type, blockGesture, { passive: false });
    });

    document.addEventListener('touchmove', (event) => {
        if (event.touches.length > 1) {
            event.preventDefault();
        }
    }, { passive: false });
})();

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

    window.addEventListener('pageshow', hide);
    window.addEventListener('load', hide);

    window.DoviraPageSkeleton = { show, hide };
})();

/* Modern top progress bar — instant feedback on page navigation. */
(() => {
    const el = document.querySelector('[data-route-progress]');
    if (!el) return;
    const bar = el.querySelector('.route-progress__bar');
    if (!bar) return;

    let value = 0;
    let active = false;
    let trickleTimer = null;
    let safetyTimer = null;

    const set = (next) => {
        value = Math.max(0, Math.min(1, next));
        bar.style.transform = `scaleX(${value})`;
    };

    const trickle = () => {
        window.clearInterval(trickleTimer);
        trickleTimer = window.setInterval(() => {
            if (value >= 0.9) return;
            set(value + (0.9 - value) * 0.12 + 0.01);
        }, 380);
    };

    const start = () => {
        if (active) return;
        active = true;
        el.classList.add('is-active');
        set(0.08);
        window.requestAnimationFrame(() => set(0.32));
        trickle();
        window.clearTimeout(safetyTimer);
        // If navigation is cancelled/blocked, don't leave the bar hanging.
        safetyTimer = window.setTimeout(done, 12000);
    };

    const done = () => {
        window.clearInterval(trickleTimer);
        window.clearTimeout(safetyTimer);
        if (!active) return;
        active = false;
        set(1);
        window.setTimeout(() => {
            el.classList.remove('is-active');
            window.setTimeout(() => set(0), 220);
        }, 240);
    };

    const isModifiedClick = (event) =>
        event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || isModifiedClick(event)) return;

        const link = event.target.closest('a[href]');
        if (!link) return;
        if (link.target && link.target !== '_self') return;
        if (link.hasAttribute('download') || link.dataset.noProgress === '1') return;

        const href = link.getAttribute('href') || '';
        if (!href || /^(#|mailto:|tel:|javascript:|sms:)/i.test(href)) return;

        let url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (error) {
            return;
        }

        if (url.origin !== window.location.origin) return;
        // Same-page hash navigation is not a page load.
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

        start();
    }, true);

    // Real (non-AJAX) form submits: bubble phase, so handlers that call
    // preventDefault for AJAX have already run and we skip those.
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (event.target instanceof HTMLFormElement && event.target.dataset.noProgress === '1') return;
        start();
    });

    window.addEventListener('pagehide', done);
    window.addEventListener('pageshow', done);
    window.addEventListener('load', done);

    // Exposed so programmatic navigations (e.g. search suggestions) can show feedback.
    window.DoviraRouteProgress = { start, done };
})();

(() => {
    const burger = document.querySelector('[data-burger]');
    const mobile = document.querySelector('[data-mobile]');
    const overlay = document.querySelector('[data-overlay]');
    if (!burger || !mobile || !overlay) return;
    const closeBtn = mobile.querySelector('[data-close]');

    const ANIM_MS = 360;
    let closeTimer = null;
    let lockedScrollY = 0;
    let isScrollLocked = false;

    document.documentElement.classList.remove('is-menu-open');
    burger.setAttribute('aria-expanded', 'false');
    mobile.hidden = true;
    overlay.hidden = true;

    const lockPageScroll = () => {
        if (isScrollLocked) return;
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${lockedScrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        isScrollLocked = true;
    };

    const unlockPageScroll = () => {
        if (!isScrollLocked) return;
        const top = document.body.style.top;
        const restoreY = top ? Math.abs(parseInt(top, 10)) : lockedScrollY;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        isScrollLocked = false;
        const prevScrollBehavior = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, Number.isFinite(restoreY) ? restoreY : 0);
        document.documentElement.style.scrollBehavior = prevScrollBehavior;
    };

    const setOpen = (open) => {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        burger.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            mobile.hidden = false;
            overlay.hidden = false;
            lockPageScroll();
            // Allow layout to apply before starting transition.
            requestAnimationFrame(() => {
                document.documentElement.classList.add('is-menu-open');
            });
            return;
        }

        document.documentElement.classList.remove('is-menu-open');
        unlockPageScroll();
        closeTimer = window.setTimeout(() => {
            mobile.hidden = true;
            overlay.hidden = true;
        }, ANIM_MS);
    };

    burger.addEventListener('click', () => {
        const open = burger.getAttribute('aria-expanded') !== 'true';
        setOpen(open);
    });

    mobile.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (link) setOpen(false);
    });

    overlay.addEventListener('click', () => setOpen(false));
    closeBtn?.addEventListener('click', () => setOpen(false));

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });
})();
