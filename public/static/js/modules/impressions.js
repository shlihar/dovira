/**
 * Покази карток профілів (impressions): картка з data-imp, що реально
 * зʼявилась у вʼюпорті (>=50%), потрапляє в пачку і летить одним запитом
 * на /events/impressions. Дедуп на сторінці — Set; серверний дедуп —
 * 1 показ/профіль/відвідувача/годину (ProfileAnalyticsService).
 */
(() => {
    if (!('IntersectionObserver' in window) || !window.fetch) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const seen = new Set();
    const pending = new Set();
    // Слаги карток, що стоять нагорі завдяки підписці: їх показ рахується
    // окремо, щоб власник бачив у кабінеті віддачу саме від оплати.
    const promoted = new Set();
    let flushTimer = null;

    const context = (() => {
        const path = window.location.pathname;
        if (path === '/' || path === '') return 'home';
        if (path.startsWith('/profiles/')) return 'profile_related';
        if (path.startsWith('/favorites')) return 'favorites';
        if (/top|rating|reiting|reytyng/.test(path)) return 'rating';
        if (path.startsWith('/catalog')) return 'catalog';
        return 'other';
    })();

    const deviceType = (() => {
        const ua = (navigator.userAgent || '').toLowerCase();
        if (ua.includes('ipad') || ua.includes('tablet')) return 'tablet';
        if (ua.includes('mobile') || ua.includes('iphone') || ua.includes('android')) return 'mobile';
        return 'desktop';
    })();

    const flush = () => {
        if (!pending.size) return;
        const slugs = [...pending].slice(0, 40);
        pending.clear();
        const promotedSlugs = slugs.filter((slug) => promoted.has(slug));

        // keepalive: пачка доїжджає навіть якщо користувач уже пішов зі сторінки.
        fetch('/events/impressions', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify({
                slugs,
                promoted: promotedSlugs,
                context,
                page_path: window.location.pathname,
                device_type: deviceType,
            }),
        }).catch(() => {});
    };

    const scheduleFlush = () => {
        clearTimeout(flushTimer);
        flushTimer = setTimeout(flush, 1500);
    };

    const io = new IntersectionObserver((entries) => {
        let queued = false;
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const slug = (entry.target.getAttribute('data-imp') || '').trim();
            io.unobserve(entry.target);
            if (!slug || seen.has(slug)) return;
            seen.add(slug);
            pending.add(slug);
            if (entry.target.getAttribute('data-imp-promoted') === '1') promoted.add(slug);
            queued = true;
        });
        if (queued) scheduleFlush();
    }, { threshold: 0.5 });

    const observeCards = (root) => {
        root.querySelectorAll('[data-imp]').forEach((card) => {
            if (card.__doviraImpObserved) return;
            card.__doviraImpObserved = true;
            io.observe(card);
        });
    };

    observeCards(document);

    // Каталог перемальовує результати без перезавантаження — підхоплюємо
    // нові картки через MutationObserver.
    new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            for (const node of mutation.addedNodes) {
                if (node.nodeType !== 1) continue;
                if (node.hasAttribute?.('data-imp') || node.querySelector?.('[data-imp]')) {
                    observeCards(node.hasAttribute('data-imp') ? node.parentElement || document : node);
                }
            }
        }
    }).observe(document.body, { childList: true, subtree: true });

    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flush();
    });
})();
