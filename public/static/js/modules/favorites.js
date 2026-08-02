/**
 * «Обране»: збереження профілів кнопкою-закладкою на картках.
 *  - Гість: список у localStorage — працює без реєстрації.
 *  - Авторизований: список у БД; при вході гостьовий список зливається.
 *  - Кнопки на AJAX-вставлених картках підхоплюються MutationObserver-ом.
 */
(() => {
    const KEY = 'dovira:favorites:v1';
    const authed = document.body.dataset.authenticated === '1';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    const readLocal = () => {
        try {
            const parsed = JSON.parse(window.localStorage.getItem(KEY) || '[]');
            return Array.isArray(parsed) ? parsed.filter((s) => typeof s === 'string') : [];
        } catch (error) {
            return [];
        }
    };
    const writeLocal = (list) => {
        try { window.localStorage.setItem(KEY, JSON.stringify(list)); } catch (error) { /* приватний режим */ }
    };

    let slugs = readLocal();
    const isSaved = (slug) => slugs.includes(slug);

    const updateCounters = () => {
        document.querySelectorAll('[data-favorites-count]').forEach((badge) => {
            badge.textContent = String(Math.min(slugs.length, 99));
            badge.hidden = slugs.length === 0;
        });
    };

    const paintButton = (button) => {
        const slug = button.dataset.favoriteSlug || '';
        const saved = isSaved(slug);
        button.classList.toggle('is-saved', saved);
        button.setAttribute('aria-pressed', saved ? 'true' : 'false');
        button.setAttribute('aria-label', saved ? 'Прибрати з обраного' : 'Зберегти профіль');
        const icon = button.querySelector('i');
        if (icon) icon.className = saved ? 'fa-solid fa-bookmark' : 'fa-regular fa-bookmark';
    };

    const paintAll = (root = document) => {
        (root.querySelectorAll ? root : document).querySelectorAll('[data-favorite-toggle]').forEach(paintButton);
    };

    const setSlugs = (list) => {
        slugs = [...new Set(list)];
        writeLocal(slugs);
        paintAll();
        updateCounters();
    };

    const postJson = async (url, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    };

    // --- Тогл ---
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-favorite-toggle]');
        if (!button) return;
        event.preventDefault();

        const slug = button.dataset.favoriteSlug || '';
        if (!slug) return;

        const nowSaved = !isSaved(slug);
        // Оптимістично: миттєва реакція, сервер наздоганяє.
        setSlugs(nowSaved ? [slug, ...slugs] : slugs.filter((s) => s !== slug));
        button.classList.add('is-pop');
        window.setTimeout(() => button.classList.remove('is-pop'), 320);

        if (authed) {
            postJson(`/favorites/${encodeURIComponent(slug)}/toggle`)
                .then((payload) => setSlugs(payload.slugs || []))
                .catch(() => { /* лишаємо оптимістичний стан */ });
        }

        // На сторінці «Обране» зняття закладки прибирає картку.
        if (!nowSaved && document.body.classList.contains('page-favorites')) {
            const card = button.closest('.dovira-catalog-card');
            if (card) {
                card.style.transition = 'opacity 180ms ease, transform 180ms ease';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.97)';
                window.setTimeout(() => {
                    card.remove();
                    syncFavoritesPageEmptyState();
                }, 190);
            }
        }
    });

    // --- AJAX-вставлені картки ---
    const observer = new MutationObserver((mutations) => {
        const touched = mutations.some((mutation) => Array.from(mutation.addedNodes).some(
            (node) => node.nodeType === 1
                && (node.matches?.('[data-favorite-toggle]') || node.querySelector?.('[data-favorite-toggle]'))
        ));
        if (touched) paintAll();
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // --- Сторінка «Обране» ---
    const favoritesGrid = document.querySelector('[data-favorites-grid]');
    const favoritesEmpty = document.querySelector('[data-favorites-empty]');

    const syncFavoritesPageEmptyState = () => {
        if (!favoritesGrid || !favoritesEmpty) return;
        const hasCards = favoritesGrid.querySelector('.dovira-catalog-card') !== null;
        favoritesEmpty.hidden = hasCards;
        favoritesGrid.hidden = !hasCards;
    };

    const hydrateGuestFavoritesPage = async () => {
        if (!favoritesGrid || authed) {
            syncFavoritesPageEmptyState();
            return;
        }
        if (!slugs.length) {
            syncFavoritesPageEmptyState();
            return;
        }
        try {
            const payload = await postJson('/favorites/resolve', { slugs });
            if (typeof payload.html === 'string' && payload.html.trim() !== '') {
                favoritesGrid.innerHTML = payload.html;
                paintAll(favoritesGrid);
                window.DoviraFitCardTags?.(favoritesGrid);
            }
            // Викидаємо з локального списку профілі, яких більше немає.
            if (Array.isArray(payload.found)) {
                setSlugs(slugs.filter((slug) => payload.found.includes(slug)));
            }
        } catch (error) { /* залишаємо порожній стан */ }
        syncFavoritesPageEmptyState();
    };

    // --- Ініціалізація ---
    paintAll();
    updateCounters();

    if (authed) {
        (async () => {
            try {
                const local = readLocal();
                const server = await fetch('/favorites/slugs', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                }).then((r) => (r.ok ? r.json() : { slugs: [] }));
                const serverSlugs = server.slugs || [];
                const extras = local.filter((slug) => !serverSlugs.includes(slug));
                if (extras.length) {
                    // Гостьові збереження підтягуються в акаунт після входу.
                    const merged = await postJson('/favorites/merge', { slugs: extras });
                    setSlugs(merged.slugs || serverSlugs);
                } else {
                    setSlugs(serverSlugs);
                }
            } catch (error) { /* мірор лишиться локальним */ }
            syncFavoritesPageEmptyState();
        })();
    } else {
        hydrateGuestFavoritesPage();
    }
})();
