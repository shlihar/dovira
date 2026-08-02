/*
 * Трекер дій користувача: пошук, фільтри, сортування, воронка відгуків, CTA.
 * Події летять у POST /events/ui (site_page_events, event_type + event_label).
 * Глобальний хелпер window.doviraTrack(event, label) доступний іншим модулям.
 */
(() => {
    const endpoint = '/events/ui';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const detectDeviceType = () => {
        const ua = (navigator.userAgent || '').toLowerCase();
        if (ua.includes('ipad') || ua.includes('tablet')) return 'tablet';
        if (ua.includes('mobile') || ua.includes('iphone') || ua.includes('android')) return 'mobile';
        return 'desktop';
    };

    const send = (eventType, label = '') => {
        const payload = {
            event_type: eventType,
            event_label: String(label || '').slice(0, 255),
            page_path: window.location.pathname,
            device_type: detectDeviceType(),
        };

        const body = JSON.stringify(payload);

        // sendBeacon переживає перехід на іншу сторінку (кліки по посиланнях).
        if (navigator.sendBeacon) {
            const blob = new Blob([body], { type: 'application/json' });
            if (navigator.sendBeacon(endpoint, blob)) return;
        }

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body,
            keepalive: true,
        }).catch(() => {});
    };

    window.doviraTrack = send;

    /* ---- A/B hero головної ----
       hero_view = показ варіанта (знаменник конверсії),
       hero_cta  = цільова дія з hero: пошук / категорія / відгук. */
    const heroEl = document.querySelector('[data-hero-variant]');
    if (heroEl) {
        const heroVariant = heroEl.dataset.heroVariant || 'base';
        send('hero_view', heroVariant);

        document.addEventListener('submit', (event) => {
            if (event.target.closest('[data-hero-variant] form[action*="/catalog"]')) {
                send('hero_cta', heroVariant + ':search');
            }
        }, true);

        document.addEventListener('click', (event) => {
            const hero = event.target.closest('[data-hero-variant]');
            if (!hero) return;
            if (event.target.closest('.home-intents__item, .hero__bento-cat')) {
                send('hero_cta', heroVariant + ':category');
            } else if (event.target.closest('[data-open-review-popup]')) {
                send('hero_cta', heroVariant + ':review');
            } else if (event.target.closest('.hero__bento-pro')) {
                send('hero_cta', heroVariant + ':pro');
            }
        }, true);
    }

    /* ---- Пошук ---- */

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[action*="/catalog"]');
        if (!form) return;
        const query = (form.querySelector('input[name="q"]')?.value || '').trim();
        if (query) send('search_query', query.toLowerCase());
    }, true);

    /* ---- Кліки (делеговано, працює і в AJAX-підвантаженому контенті) ---- */

    document.addEventListener('click', (event) => {
        const suggestLink = event.target.closest('[data-search-suggest] a, [data-mobile-search-results] a');
        if (suggestLink) {
            send('search_suggest_click', (suggestLink.textContent || '').trim().slice(0, 120));
            return;
        }

        if (event.target.closest('[data-catalog-apply-filters]')) {
            send('catalog_filter_apply', 'mobile_apply');
            return;
        }

        if (event.target.closest('[data-catalog-load-more]')) {
            send('catalog_load_more');
            return;
        }

        const seoChip = event.target.closest('.catalog-seo-links__item');
        if (seoChip) {
            send('cta_click', 'seo_category:' + (seoChip.textContent || '').trim().slice(0, 100));
            return;
        }

        if (event.target.closest('.catalog-add-profile-btn')) {
            send('cta_click', 'add_profile');
            return;
        }

        const reviewTrigger = event.target.closest('[data-open-review-popup]');
        if (reviewTrigger && reviewTrigger.getAttribute('data-review-mode') !== 'edit') {
            send('review_popup_open', reviewTrigger.getAttribute('data-profile-slug') || '');
            return;
        }

        const payMethod = event.target.closest('.pay-method');
        if (payMethod) {
            const action = payMethod.closest('form')?.getAttribute('action') || '';
            send('cta_click', action.includes('stars') ? 'pay_stars' : 'pay_crypto');
        }
    }, true);

    /* ---- Фільтри й сортування каталогу ---- */

    document.addEventListener('change', (event) => {
        const sortSelect = event.target.closest('[data-catalog-sort-select]');
        if (sortSelect) {
            send('catalog_sort_change', sortSelect.value);
            return;
        }

        const filterInput = event.target.closest('[data-catalog-filters-form] input, [data-catalog-filters-form] select');
        if (filterInput && filterInput.name && filterInput.name !== 'sort') {
            const value = filterInput.type === 'checkbox' && !filterInput.checked
                ? 'off'
                : String(filterInput.value || 'on').slice(0, 100);
            send('catalog_filter_apply', `${filterInput.name}:${value}`);
        }
    }, true);
})();
