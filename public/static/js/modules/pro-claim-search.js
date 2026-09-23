/**
 * Пошук своєї компанії просто на сторінці /pro.
 *
 * Навіщо: раніше кнопка «Знайти свій профіль» вела на звичайну реєстрацію, і
 * після неї людина опинялась на головній — намір губився, шлях доводилось
 * відновлювати вручну. Тепер профіль обирається ДО реєстрації, а його id їде
 * далі в параметрі `next`, тож після створення акаунта людина одразу бачить
 * підтвердження прав саме на свою компанію.
 */
(() => {
    const root = document.querySelector('[data-pro-claim-search]');
    if (!root) return;

    const input = root.querySelector('[data-pro-claim-input]');
    const results = root.querySelector('[data-pro-claim-results]');
    if (!input || !results) return;

    const isAuthed = root.dataset.authed === '1';
    const accountUrl = root.dataset.accountUrl || '/pro/account';
    const registerUrl = root.dataset.registerUrl || '/register';

    // Куди веде вибір профілю: авторизований — одразу в кабінет на вкладку
    // підтвердження, гість — на реєстрацію, яка після себе поверне туди ж.
    const destinationFor = (profileId) => {
        const claimUrl = `${accountUrl}?tab=claims&claim_profile=${encodeURIComponent(profileId)}`;

        return isAuthed ? claimUrl : `${registerUrl}?next=${encodeURIComponent(claimUrl)}`;
    };

    let controller = null;
    let timer = null;
    let items = [];
    let activeIndex = -1;

    const close = () => {
        results.hidden = true;
        results.innerHTML = '';
        items = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
    };

    const setActive = (index) => {
        const nodes = [...results.querySelectorAll('[data-pro-claim-option]')];
        if (!nodes.length) return;

        activeIndex = (index + nodes.length) % nodes.length;
        nodes.forEach((node, i) => node.classList.toggle('is-active', i === activeIndex));
        nodes[activeIndex].scrollIntoView({ block: 'nearest' });
    };

    const render = (list) => {
        items = list;

        if (!list.length) {
            results.innerHTML = '<p class="pro-claim-search__empty">Нічого не знайшли. Спробуйте назву без лапок або місто.</p>';
            results.hidden = false;
            return;
        }

        results.innerHTML = list
            .map((item, index) => `
                <button type="button" class="pro-claim-search__option" data-pro-claim-option data-index="${index}">
                    <span class="pro-claim-search__name">${escapeHtml(item.label)}</span>
                    ${item.meta ? `<span class="pro-claim-search__meta">${escapeHtml(item.meta)}</span>` : ''}
                </button>
            `)
            .join('');

        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activeIndex = -1;
    };

    const escapeHtml = (value) => String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');

    const search = async (query) => {
        controller?.abort();
        controller = new AbortController();

        try {
            const response = await fetch(
                `/search/suggest?scope=claim_profiles&q=${encodeURIComponent(query)}`,
                { signal: controller.signal, headers: { Accept: 'application/json' } }
            );
            if (!response.ok) return;

            const data = await response.json();
            render((data.items || []).filter((item) => item.profile_id).slice(0, 6));
        } catch (error) {
            // Перерваний запит (користувач друкує далі) — не помилка.
        }
    };

    input.addEventListener('input', () => {
        const query = input.value.trim();
        clearTimeout(timer);

        if (query.length < 2) {
            close();
            return;
        }

        timer = setTimeout(() => search(query), 250);
    });

    input.addEventListener('keydown', (event) => {
        if (results.hidden) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive(activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive(activeIndex - 1);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            go(items[activeIndex]);
        } else if (event.key === 'Escape') {
            close();
        }
    });

    const go = (item) => {
        if (!item?.profile_id) return;
        window.location.href = destinationFor(item.profile_id);
    };

    results.addEventListener('click', (event) => {
        const option = event.target.closest('[data-pro-claim-option]');
        if (!option) return;
        go(items[Number(option.dataset.index)]);
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) close();
    });

    // Форма без JS веде в кабінет; з JS — Enter без обраного профілю нічого
    // не ламає, просто шукаємо далі.
    root.addEventListener('submit', (event) => {
        event.preventDefault();
        if (activeIndex >= 0) go(items[activeIndex]);
    });
})();
