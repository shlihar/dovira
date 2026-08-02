// Секція «Категорії відгуків»: кнопка «Показати ще» + ротація стрічки активності.
// Стрічка ротується лише коли є ≥2 записи й не увімкнено prefers-reduced-motion.
(() => {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Кнопка «Показати ще N категорій»: знімає клас-обмежувач сітки. ---
    document.querySelectorAll('[data-cat-more]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const grid = btn.closest('.categories')?.querySelector('[data-cat-grid]');
            if (grid) {
                grid.classList.add('is-expanded');
            }
            btn.remove();
        });
    });

    // --- Мобільний app-like перемикач категорій: пігулка → своя картка-панель. ---
    const pills = Array.from(document.querySelectorAll('[data-cat-pill]'));
    const panels = Array.from(document.querySelectorAll('[data-cat-panel]'));
    pills.forEach((pill) => {
        pill.addEventListener('click', () => {
            const idx = pill.dataset.catPill;
            pills.forEach((p) => {
                p.classList.toggle('is-active', p === pill);
                p.setAttribute('aria-selected', p === pill ? 'true' : 'false');
            });
            panels.forEach((panel) => {
                panel.classList.toggle('is-active', panel.dataset.catPanel === idx);
            });
            pill.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    });

    // --- Стрічка активності ---
    // Час нарощується від завантаження сторінки, щоб рядок «жив» під час сесії.
    const pageLoad = Date.now();
    const elapsedMin = () => Math.floor((Date.now() - pageLoad) / 60000);

    const label = (m) => {
        if (m < 60) return `${m} хв тому`;
        const hours = Math.floor(m / 60);
        if (hours < 24) return `${hours} год тому`;
        return `${Math.floor(hours / 24)} дн тому`;
    };

    const renderItem = (item) => ({
        href: `/catalog/${item.category_slug}`,
        text: `${label((item.minutes_ago || 1) + elapsedMin())} · новий відгук у категорії «${item.category_name}»`,
    });

    document.querySelectorAll('[data-cat-ticker]').forEach((el) => {
        let items;
        try {
            items = JSON.parse(el.dataset.items || '[]');
        } catch {
            items = [];
        }
        if (!Array.isArray(items) || items.length === 0) {
            return;
        }

        const textEl = el.querySelector('[data-cat-ticker-text]');
        if (!textEl) {
            return;
        }

        const apply = (i) => {
            const r = renderItem(items[i]);
            textEl.textContent = r.text;
            el.setAttribute('href', r.href);
        };

        apply(0);

        if (reduceMotion || items.length < 2) {
            return; // без ротації — статичний перший запис
        }

        let index = 0;
        setInterval(() => {
            index = (index + 1) % items.length;
            el.classList.add('is-fading');
            setTimeout(() => {
                apply(index);
                el.classList.remove('is-fading');
            }, 220);
        }, 3400);
    });
})();
