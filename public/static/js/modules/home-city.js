// Місто на головній: автовизначення (кукі → IP, без браузерних дозволів)
// плюс явний вибір у селекторі hero-пошуку. Персоналізує секції
// «Dovira рекомендує» і «Топ-рейтинги» (профілі міста першими) та
// підставляє місто в пошук: прихований input regions[] летить у каталог.
// SSR-версія секцій глобальна і однакова для всіх — кешування не ламається.
(() => {
    if (!document.body.classList.contains('page-home-main')) return;

    const recommendedTrack = document.querySelector('[data-carousel="recommended-lawyers"]');
    const topBoard = document.querySelector('[data-home-top-board]');
    const leaderboardShell = document.querySelector('[data-home-leaderboard]');
    const reviewsTrack = document.querySelector('[data-carousel="latest-reviews"]');
    const pickers = Array.from(document.querySelectorAll('[data-city-picker]'));
    const cityInputs = Array.from(document.querySelectorAll('[data-city-input]'));
    const cityBadges = Array.from(document.querySelectorAll('[data-city-badge]'));

    if (!recommendedTrack && !topBoard && !leaderboardShell && !reviewsTrack && !pickers.length) return;

    // Знімок глобальної SSR-розмітки — щоб «Вся Україна» повертала її
    // без перезавантаження сторінки.
    const initialHtml = {
        recommended: recommendedTrack ? recommendedTrack.innerHTML : '',
        top: topBoard ? topBoard.innerHTML : '',
        leaderboard: leaderboardShell ? leaderboardShell.innerHTML : '',
        reviews: reviewsTrack ? reviewsTrack.innerHTML : '',
    };

    const initLeaderboardTabs = (root = document) => {
        root.querySelectorAll('.leaderboard').forEach((leaderboard) => {
            if (leaderboard.dataset.lbReady === '1') return;
            leaderboard.dataset.lbReady = '1';

            leaderboard.addEventListener('click', (event) => {
                const tab = event.target.closest('[data-lb-tab]');
                if (!tab || !leaderboard.contains(tab)) return;

                const idx = tab.dataset.lbTab;
                const tabs = Array.from(leaderboard.querySelectorAll('[data-lb-tab]'));
                const panels = Array.from(leaderboard.querySelectorAll('[data-lb-panel]'));

                tabs.forEach((item) => {
                    const active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach((panel) => {
                    panel.classList.toggle('is-active', panel.dataset.lbPanel === idx);
                });
            });
        });
    };

    const setCity = (city) => {
        const label = city || 'Вся Україна';
        pickers.forEach((picker) => {
            const labelNode = picker.querySelector('[data-city-label]');
            if (labelNode) labelNode.textContent = label;
            picker.querySelectorAll('[data-city-option]').forEach((option) => {
                option.classList.toggle('is-active', (option.dataset.cityOption || '') === (city || ''));
            });
        });
        cityInputs.forEach((input) => {
            input.value = city || '';
            input.disabled = !city;
        });
        // Бейдж міста в заголовках персоналізованих секцій.
        cityBadges.forEach((badge) => {
            badge.textContent = city ? `· ${city}` : '';
            badge.hidden = !city;
        });
    };

    const applyPayload = (payload, { restoreOnEmpty = false } = {}) => {
        const html = payload && payload.html;
        if (payload && payload.city && html) {
            if (recommendedTrack && typeof html.recommended === 'string' && html.recommended.trim() !== '') {
                recommendedTrack.innerHTML = html.recommended;
                // Карусель прокручена — повертаємо на початок, де тепер
                // стоять профілі міста користувача.
                recommendedTrack.scrollLeft = 0;
            }
            if (topBoard && typeof html.top === 'string' && html.top.trim() !== '') {
                topBoard.innerHTML = html.top;
            }
            if (reviewsTrack && typeof html.reviews === 'string' && html.reviews.trim() !== '') {
                reviewsTrack.innerHTML = html.reviews;
                reviewsTrack.scrollLeft = 0;
            }
            if (leaderboardShell && typeof html.leaderboard === 'string') {
                leaderboardShell.innerHTML = html.leaderboard;
                initLeaderboardTabs(leaderboardShell);
            }
            setCity(payload.city);
            // Свіжовставлені картки треба «дофітити»: рядок напрямів
            // згортається у «+N» модулем card-tags-tooltip (як у каталозі).
            window.DoviraFitCardTags?.(document);
            return;
        }
        if (restoreOnEmpty) {
            if (recommendedTrack) recommendedTrack.innerHTML = initialHtml.recommended;
            if (topBoard) topBoard.innerHTML = initialHtml.top;
            if (leaderboardShell) {
                leaderboardShell.innerHTML = initialHtml.leaderboard;
                initLeaderboardTabs(leaderboardShell);
            }
            if (reviewsTrack) reviewsTrack.innerHTML = initialHtml.reviews;
            setCity('');
            window.DoviraFitCardTags?.(document);
        }
    };

    const fetchSections = async (city) => {
        const url = city === undefined
            ? '/geo/home-sections'
            : `/geo/home-sections?city=${encodeURIComponent(city === '' ? 'all' : city)}`;
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!response.ok) return null;
        return response.json();
    };

    const closeMenus = () => {
        pickers.forEach((picker) => {
            picker.classList.remove('is-open');
            const menu = picker.querySelector('[data-city-menu]');
            const toggle = picker.querySelector('[data-city-toggle]');
            if (menu) menu.hidden = true;
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    };

    pickers.forEach((picker) => {
        const toggle = picker.querySelector('[data-city-toggle]');
        const menu = picker.querySelector('[data-city-menu]');
        if (!toggle || !menu) return;

        toggle.addEventListener('click', (event) => {
            event.preventDefault();
            const willOpen = menu.hidden;
            closeMenus();
            if (willOpen) {
                menu.hidden = false;
                picker.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
            }
        });

        menu.addEventListener('click', async (event) => {
            const option = event.target.closest('[data-city-option]');
            if (!option) return;
            const city = option.dataset.cityOption || '';
            closeMenus();
            // Лейбл і input оновлюємо одразу, щоб пошук працював навіть
            // якщо запит секцій ще летить чи впав.
            setCity(city);
            try {
                const payload = await fetchSections(city);
                applyPayload(payload, { restoreOnEmpty: true });
            } catch (error) {
                // Персоналізація — прогресивне покращення; вибране місто
                // все одно піде в пошук через прихований input.
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-city-picker]')) closeMenus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenus();
    });

    initLeaderboardTabs(document);

    // Стартове визначення міста (кукі або IP).
    (async () => {
        try {
            const payload = await fetchSections();
            applyPayload(payload);
        } catch (error) {
            // Мовчки лишаємо глобальні списки.
        }
    })();
})();
