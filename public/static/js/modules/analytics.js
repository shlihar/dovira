(() => {
    const endpoint = '/events/site-page-view';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const detectDeviceType = () => {
        const ua = (navigator.userAgent || '').toLowerCase();
        if (ua.includes('ipad') || ua.includes('tablet')) return 'tablet';
        if (ua.includes('mobile') || ua.includes('iphone') || ua.includes('android')) return 'mobile';
        return 'desktop';
    };

    const readCookie = (name) => {
        const parts = document.cookie ? document.cookie.split('; ') : [];
        for (const part of parts) {
            const [key, ...rest] = part.split('=');
            if (key === name) {
                return decodeURIComponent(rest.join('='));
            }
        }
        return '';
    };

    const writeCookie = (name, value) => {
        const maxAge = 60 * 60 * 24 * 365;
        document.cookie = `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; samesite=lax`;
    };

    const ensureVisitorId = () => {
        const existing = readCookie('dovira_visitor_id');
        if (existing) return existing;

        const randomPart = Math.random().toString(36).slice(2);
        const value = `${Date.now().toString(36)}-${randomPart}`;
        writeCookie('dovira_visitor_id', value);

        return value;
    };

    const detectSource = (searchParams, referrer) => {
        const explicit = (searchParams.get('source') || '').trim();
        if (explicit) return explicit;

        const utmSource = (searchParams.get('utm_source') || '').trim();
        if (utmSource) return utmSource;

        if (!referrer) return 'direct';

        const lowerRef = referrer.toLowerCase();
        if (lowerRef.includes('google.')) return 'google';
        if (lowerRef.includes('facebook.')) return 'facebook';
        if (lowerRef.includes('instagram.')) return 'instagram';
        if (lowerRef.includes('t.me') || lowerRef.includes('telegram.')) return 'telegram';

        return 'referral';
    };

    const pageKey = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    const tracked = window.__doviraSitePageViewTracked instanceof Set
        ? window.__doviraSitePageViewTracked
        : new Set();
    window.__doviraSitePageViewTracked = tracked;

    if (tracked.has(pageKey)) return;
    tracked.add(pageKey);

    const sendPageView = () => {
        const params = new URLSearchParams(window.location.search);
        const referrer = document.referrer || '';

        const payload = {
            event_type: 'site_page_view',
            page_path: window.location.pathname,
            page_url: window.location.href,
            source: detectSource(params, referrer),
            internal_source: (params.get('from') || '').trim(),
            referrer,
            utm_source: (params.get('utm_source') || '').trim(),
            utm_medium: (params.get('utm_medium') || '').trim(),
            utm_campaign: (params.get('utm_campaign') || '').trim(),
            utm_content: (params.get('utm_content') || '').trim(),
            utm_term: (params.get('utm_term') || '').trim(),
            device_type: detectDeviceType(),
        };

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify(payload),
        })
            .then((response) => response.json().catch(() => null))
            .then((data) => {
                if (data?.visitor_id && !readCookie('dovira_visitor_id')) {
                    writeCookie('dovira_visitor_id', data.visitor_id);
                }
            })
            .catch(() => {});
    };

    // Defer tracking until the page is actually shown, so prerendered pages
    // don't inflate views before the user navigates to them.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', sendPageView, { once: true });
    } else {
        sendPageView();
    }
})();

(() => {
    const profileRoot = document.querySelector('.profile-page[data-profile-slug]');
    if (!profileRoot) return;

    const profileSlug = profileRoot.getAttribute('data-profile-slug');
    if (!profileSlug) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const url = `/profiles/${encodeURIComponent(profileSlug)}/events`;

    const detectDeviceType = () => {
        const ua = (navigator.userAgent || '').toLowerCase();
        if (ua.includes('ipad') || ua.includes('tablet')) return 'tablet';
        if (ua.includes('mobile') || ua.includes('iphone') || ua.includes('android')) return 'mobile';
        return 'desktop';
    };

    const basePayload = () => {
        const params = new URLSearchParams(window.location.search);
        return {
            source: params.get('source') || 'direct',
            internal_source: params.get('from') || 'profile_page',
            referrer: document.referrer || '',
            utm_source: params.get('utm_source') || '',
            utm_medium: params.get('utm_medium') || '',
            utm_campaign: params.get('utm_campaign') || '',
            utm_content: params.get('utm_content') || '',
            utm_term: params.get('utm_term') || '',
            device_type: detectDeviceType(),
        };
    };

    const track = (eventType, targetUrl = '') => {
        const payload = {
            event_type: eventType,
            target_url: targetUrl || '',
            ...basePayload(),
        };

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify(payload),
        }).catch(() => {});
    };

    // Дати змогу іншим модулям/inline-скриптам профілю слати події воронки
    // (вкладка «Інформація», відкриття/перегляд досьє) з тими самими UTM.
    // Кожен тип події шлемо не більше разу на завантаження сторінки.
    const funnelSent = new Set();
    window.doviraProfileTrack = (eventType, targetUrl = '') => {
        if (funnelSent.has(eventType)) return;
        funnelSent.add(eventType);
        track(eventType, targetUrl);
    };

    const trackedViews = window.__doviraProfileViewTracked instanceof Set
        ? window.__doviraProfileViewTracked
        : new Set();
    window.__doviraProfileViewTracked = trackedViews;

    const trackInitialView = () => {
        if (trackedViews.has(profileSlug)) return;
        track('profile_view', window.location.href);
        trackedViews.add(profileSlug);
    };

    // Don't count a profile view while the page is only prerendered.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', trackInitialView, { once: true });
    } else {
        trackInitialView();
    }

    const allowedExplicitEvents = new Set([
        'website_click',
        'phone_click',
        'email_click',
        'telegram_click',
        'viber_click',
        'whatsapp_click',
        'instagram_click',
        'facebook_click',
        'map_click',
    ]);

    const resolveEventType = (anchor) => {
        const explicit = anchor.getAttribute('data-profile-track');
        return explicit && allowedExplicitEvents.has(explicit) ? explicit : null;
    };

    profileRoot.querySelectorAll('a[href]').forEach((anchor) => {
        anchor.addEventListener('click', () => {
            const eventType = resolveEventType(anchor);
            if (!eventType) return;
            track(eventType, anchor.getAttribute('href') || window.location.href);
        });
    });
})();

