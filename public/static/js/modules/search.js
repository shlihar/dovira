const MOBILE_BREAKPOINT = 900;
const DESKTOP_OVERLAY_BREAKPOINT = 900;
const SUGGEST_ENDPOINT = '/search/suggest';
const SUGGEST_LIMIT = 8;
const MIN_QUERY_LENGTH = 2;
const INPUT_DEBOUNCE_MS = 220;
const MOBILE_CLOSE_DELAY = 240;

const cache = new Map();
const desktopControllers = new Map();

const isElement = (value) => value instanceof Element || value instanceof Document;

const isMobileViewport = () => window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;
const isDesktopOverlayViewport = () => window.matchMedia(`(min-width: ${DESKTOP_OVERLAY_BREAKPOINT + 1}px)`).matches;

const collectSearchForms = (root = document) => {
    if (!isElement(root)) return [];

    const forms = new Set();

    if (root instanceof Element && root.matches('[data-search-form]')) {
        forms.add(root);
    }

    root.querySelectorAll('[data-search-form]').forEach((form) => forms.add(form));

    return Array.from(forms);
};

const fetchSuggestions = async (query, scope = 'all') => {
    const normalizedQuery = String(query || '').trim();
    const normalizedScope = String(scope || 'all').trim() || 'all';
    const cacheKey = `${normalizedScope}::${normalizedQuery}`;

    if (cache.has(cacheKey)) {
        return cache.get(cacheKey);
    }

    const url = new URL(SUGGEST_ENDPOINT, window.location.origin);
    url.searchParams.set('scope', normalizedScope);
    url.searchParams.set('limit', String(SUGGEST_LIMIT));
    url.searchParams.set('q', normalizedQuery);

    const request = fetch(url.toString(), {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
        },
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Suggest request failed: ${response.status}`);
            }

            return response.json();
        })
        .then((payload) => Array.isArray(payload?.items) ? payload.items : [])
        .catch(() => []);

    cache.set(cacheKey, request);

    return request;
};

const createSuggestItem = (item, className = 'hero__search-suggest-item') => {
    const link = document.createElement('a');
    link.className = className;
    link.dataset.searchItem = '1';
    link.dataset.url = String(item.url || '');
    link.dataset.label = String(item.label || '');
    link.dataset.query = String(item.query || '');
    link.dataset.scope = String(item.scope || 'all');
    link.href = String(item.url || '#');

    const icon = document.createElement('i');
    icon.className = item.icon || 'fa-solid fa-magnifying-glass';
    icon.setAttribute('aria-hidden', 'true');
    link.appendChild(icon);

    const text = document.createElement('span');
    text.className = 'hero__search-suggest-text';

    const label = document.createElement('span');
    label.className = 'hero__search-suggest-label';
    label.textContent = item.label || '';
    text.appendChild(label);

    if (item.meta) {
        const meta = document.createElement('span');
        meta.className = 'hero__search-suggest-meta';
        meta.textContent = item.meta;
        text.appendChild(meta);
    }

    link.appendChild(text);

    return link;
};

// Категорії — окремою секцією зверху: сервер віддає їх першими, а тут
// вони отримують власний заголовок, щоб відділятись від профілів.
const groupSuggestions = (items) => {
    const categories = items.filter((item) => item.type === 'category');
    const rest = items.filter((item) => item.type !== 'category');

    if (!categories.length || !rest.length) {
        return [{ title: 'Результати', items }];
    }

    return [
        { title: 'Категорії', items: categories },
        { title: rest.every((item) => item.type === 'profile') ? 'Профілі' : 'Результати', items: rest },
    ];
};

const createSuggestSection = (title, items, options = {}) => {
    const fragment = document.createDocumentFragment();
    const itemClass = options.itemClass || 'hero__search-suggest-item';

    if (title) {
        const heading = document.createElement('p');
        heading.className = 'hero__search-suggest-title';
        heading.textContent = title;
        fragment.appendChild(heading);
    }

    items.forEach((item) => {
        fragment.appendChild(createSuggestItem(item, itemClass));
    });

    return fragment;
};

/* -------------------------------------------------------------------------- */
/* Desktop dropdown suggestions                                               */
/* -------------------------------------------------------------------------- */

const cleanupDisconnectedDesktopForms = () => {
    desktopControllers.forEach((controller, form) => {
        if (form.isConnected) return;
        controller.abort();
        desktopControllers.delete(form);
    });
};

const initDesktopSuggest = (form) => {
    if (desktopControllers.has(form)) return;

    const input = form.querySelector('input[name="q"]');
    const suggest = form.querySelector('[data-search-suggest]');

    if (!input || !suggest) return;

    const controller = new AbortController();
    const { signal } = controller;
    const scope = form.dataset.searchScope || 'all';
    let activeIndex = -1;
    let debounceId = 0;
    let requestId = 0;

    // Mobile-app forms (hero/catalog mobile fields) delegate to the full-screen
    // modal on small screens, so their inline dropdown must never open there.
    const isMobileAppForm = form.hasAttribute('data-search-mobile-app');
    const suppressed = () => isMobileAppForm && isMobileViewport();

    const getSuggestItems = () => Array.from(suggest.querySelectorAll('.hero__search-suggest-item'));

    const setActiveIndex = (nextIndex) => {
        const items = getSuggestItems();
        items.forEach((item) => item.classList.remove('is-active'));

        if (!items.length) {
            activeIndex = -1;
            return;
        }

        activeIndex = nextIndex;

        if (activeIndex >= 0 && items[activeIndex]) {
            items[activeIndex].classList.add('is-active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    };

    const hideSuggest = () => {
        suggest.hidden = true;
        form.classList.remove('is-open');
        activeIndex = -1;

        if (isDesktopOverlayViewport()) {
            const anyOpen = collectSearchForms(document).some((candidate) => candidate.classList.contains('is-open'));
            if (!anyOpen) {
                document.body.classList.remove('search-focus-open');
            }
        }
    };

    const showSuggest = () => {
        suggest.hidden = false;
        form.classList.add('is-open');

        if (isDesktopOverlayViewport()) {
            document.body.classList.add('search-focus-open');
        }
    };

    const renderEmpty = (message) => {
        suggest.innerHTML = '';
        const empty = document.createElement('p');
        empty.className = 'hero__search-suggest-empty';
        empty.textContent = message;
        suggest.appendChild(empty);
        showSuggest();
    };

    const renderSuggest = (sections) => {
        suggest.innerHTML = '';

        sections.forEach((section) => {
            if (!Array.isArray(section.items) || !section.items.length) return;
            suggest.appendChild(createSuggestSection(section.title, section.items));
        });

        if (!suggest.children.length) {
            renderEmpty('Почніть вводити запит.');
            return;
        }

        showSuggest();
    };

    const loadSuggest = () => {
        if (suppressed()) return;

        const value = input.value.trim();
        const currentRequestId = ++requestId;

        window.clearTimeout(debounceId);
        debounceId = window.setTimeout(async () => {
            if (value.length >= MIN_QUERY_LENGTH) {
                const items = await fetchSuggestions(value, scope);
                if (currentRequestId !== requestId) return;

                if (!items.length) {
                    renderEmpty('Нічого не знайдено.');
                    return;
                }

                renderSuggest(groupSuggestions(items));
                return;
            }

            if (value.length > 0) {
                renderEmpty(`Введіть щонайменше ${MIN_QUERY_LENGTH} символи.`);
                return;
            }

            const popular = await fetchSuggestions('', scope);
            if (currentRequestId !== requestId) return;

            if (popular.length) {
                renderSuggest([{ title: 'Популярні запити', items: popular }]);
                return;
            }

            renderEmpty('Почніть вводити запит.');
        }, value ? INPUT_DEBOUNCE_MS : 0);
    };

    const handleSelect = (item) => {
        const url = item.dataset.url || '';
        const query = item.dataset.query || '';

        if (query) {
            input.value = query;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
            hideSuggest();
            return;
        }

        hideSuggest();
        window.DoviraRouteProgress?.start?.();
        window.location.href = url;
    };

    input.addEventListener('focus', () => {
        if (suppressed()) return;
        loadSuggest();
    }, { signal });

    input.addEventListener('input', () => {
        if (suppressed()) return;
        loadSuggest();
    }, { signal });

    input.addEventListener('keydown', (event) => {
        const items = getSuggestItems();

        if (!items.length || suggest.hidden) {
            if (event.key === 'Escape') hideSuggest();
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex(activeIndex < items.length - 1 ? activeIndex + 1 : 0);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(activeIndex > 0 ? activeIndex - 1 : items.length - 1);
            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            items[activeIndex]?.click();
            return;
        }

        if (event.key === 'Escape') {
            hideSuggest();
        }
    }, { signal });

    suggest.addEventListener('click', (event) => {
        const item = event.target.closest('.hero__search-suggest-item');
        if (!item) return;
        event.preventDefault();
        handleSelect(item);
    }, { signal });

    form.addEventListener('submit', () => {
        hideSuggest();
    }, { signal });

    form.addEventListener('focusout', () => {
        window.setTimeout(() => {
            if (form.contains(document.activeElement) || suggest.contains(document.activeElement)) return;
            hideSuggest();
        }, 120);
    }, { signal });

    document.addEventListener('pointerdown', (event) => {
        if (form.contains(event.target) || suggest.contains(event.target)) return;
        hideSuggest();
    }, { signal });

    signal.addEventListener('abort', () => {
        window.clearTimeout(debounceId);
    }, { once: true });

    desktopControllers.set(form, controller);
};

/* -------------------------------------------------------------------------- */
/* Full-screen mobile search modal                                            */
/* -------------------------------------------------------------------------- */

const initMobileSearch = () => {
    const modal = document.querySelector('[data-mobile-search]');
    if (!modal || modal.dataset.bound === '1') return null;
    modal.dataset.bound = '1';

    const form = modal.querySelector('[data-mobile-search-form]');
    const input = modal.querySelector('[data-mobile-search-input]');
    const results = modal.querySelector('[data-mobile-search-results]');
    const clearButton = modal.querySelector('[data-mobile-search-clear]');
    const closeButton = modal.querySelector('[data-mobile-search-close]');

    if (!form || !input || !results) return null;

    // Scope і action-джерела динамічні: за замовчуванням каталог (all), але
    // форма-джерело (напр. business-cta зі scope=claim_profiles → /pro) може
    // передати власні значення через open(value, { scope, action }).
    const defaultAction = form.getAttribute('action') || '/catalog';
    let scope = 'all';
    let submitAction = defaultAction;
    let isOpen = false;
    let activeIndex = -1;
    let debounceId = 0;
    let requestId = 0;
    let closeId = 0;

    const onCatalog = () => document.body.classList.contains('page-catalog');

    const catalogInputs = () => Array.from(document.querySelectorAll('.page-catalog [data-search-form] input[name="q"]'));

    // Push the query into the catalog's own search inputs and trigger its
    // live AJAX filtering, so typing in the modal updates results underneath.
    const applyToCatalog = (value) => {
        if (!onCatalog()) return;
        const inputs = catalogInputs();
        if (!inputs.length) return;
        inputs.forEach((node) => { node.value = value; });
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
    };

    const updateClear = () => {
        if (!clearButton) return;
        clearButton.hidden = !input.value.trim();
    };

    const getItems = () => Array.from(results.querySelectorAll('.hero__search-suggest-item'));

    const setActiveIndex = (nextIndex) => {
        const items = getItems();
        items.forEach((item) => item.classList.remove('is-active'));

        if (!items.length) {
            activeIndex = -1;
            return;
        }

        activeIndex = nextIndex;
        if (activeIndex >= 0 && items[activeIndex]) {
            items[activeIndex].classList.add('is-active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    };

    const renderEmpty = (message) => {
        results.innerHTML = '';
        const empty = document.createElement('p');
        empty.className = 'hero__search-suggest-empty';
        empty.textContent = message;
        results.appendChild(empty);
    };

    const renderSuggest = (sections) => {
        results.innerHTML = '';
        sections.forEach((section) => {
            if (!Array.isArray(section.items) || !section.items.length) return;
            results.appendChild(createSuggestSection(section.title, section.items));
        });
    };

    const loadSuggest = () => {
        const value = input.value.trim();
        const currentRequestId = ++requestId;

        window.clearTimeout(debounceId);
        debounceId = window.setTimeout(async () => {
            if (value.length >= MIN_QUERY_LENGTH) {
                const items = await fetchSuggestions(value, scope);
                if (currentRequestId !== requestId || !isOpen) return;

                if (!items.length) {
                    renderEmpty('Нічого не знайдено.');
                    return;
                }

                renderSuggest(groupSuggestions(items));
                return;
            }

            if (value.length > 0) {
                renderEmpty(`Введіть щонайменше ${MIN_QUERY_LENGTH} символи.`);
                return;
            }

            const popular = await fetchSuggestions('', scope);
            if (currentRequestId !== requestId || !isOpen) return;

            if (popular.length) {
                renderSuggest([{ title: 'Популярні запити', items: popular }]);
                return;
            }

            renderEmpty('Почніть вводити запит.');
        }, value ? INPUT_DEBOUNCE_MS : 0);
    };

    const open = (prefill, opts = {}) => {
        scope = opts.scope || 'all';
        submitAction = opts.action || defaultAction;

        if (isOpen) {
            input.focus({ preventScroll: true });
            return;
        }

        window.clearTimeout(closeId);
        isOpen = true;

        if (typeof prefill === 'string') {
            input.value = prefill;
        } else if (onCatalog()) {
            input.value = catalogInputs()[0]?.value || '';
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('mobile-search-open');
        updateClear();
        activeIndex = -1;
        loadSuggest();

        window.requestAnimationFrame(() => {
            modal.classList.add('is-open');
            input.focus({ preventScroll: true });
        });
    };

    const close = () => {
        if (!isOpen) return;
        isOpen = false;
        activeIndex = -1;
        window.clearTimeout(debounceId);

        modal.classList.remove('is-open');
        document.body.classList.remove('mobile-search-open');
        input.blur();

        window.clearTimeout(closeId);
        closeId = window.setTimeout(() => {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
        }, MOBILE_CLOSE_DELAY);
    };

    const handleSelect = (item) => {
        const url = item.dataset.url || '';
        const query = item.dataset.query || '';

        if (onCatalog() && query) {
            input.value = query;
            updateClear();
            applyToCatalog(query);
            close();
            return;
        }

        if (url) {
            window.DoviraRouteProgress?.start?.();
            window.location.href = url;
            return;
        }

        close();
    };

    input.addEventListener('input', () => {
        updateClear();
        if (onCatalog()) applyToCatalog(input.value.trim());
        loadSuggest();
    });

    input.addEventListener('keydown', (event) => {
        const items = getItems();

        if (event.key === 'Escape') {
            event.preventDefault();
            close();
            return;
        }

        if (!items.length) return;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex(activeIndex < items.length - 1 ? activeIndex + 1 : 0);
            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex(activeIndex > 0 ? activeIndex - 1 : items.length - 1);
            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            items[activeIndex]?.click();
        }
    });

    results.addEventListener('click', (event) => {
        const item = event.target.closest('.hero__search-suggest-item');
        if (!item) return;
        event.preventDefault();
        handleSelect(item);
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const value = input.value.trim();

        if (onCatalog()) {
            applyToCatalog(value);
            close();
            return;
        }

        const target = new URL(submitAction || '/catalog', window.location.origin);
        if (value) {
            target.searchParams.set('q', value);
        }
        window.DoviraRouteProgress?.start?.();
        window.location.href = target.toString();
    });

    if (clearButton) {
        clearButton.addEventListener('click', (event) => {
            event.preventDefault();
            input.value = '';
            updateClear();
            if (onCatalog()) applyToCatalog('');
            loadSuggest();
            input.focus({ preventScroll: true });
        });
    }

    if (closeButton) {
        closeButton.addEventListener('click', (event) => {
            event.preventDefault();
            close();
        });
    }

    return { open, close, isOpen: () => isOpen };
};

/* -------------------------------------------------------------------------- */
/* Wiring                                                                      */
/* -------------------------------------------------------------------------- */

let mobileSearch = null;

const wireMobileTriggers = () => {
    // Header icon (present on every page) and any explicit trigger.
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-mobile-search-open]');
        if (!trigger || !mobileSearch) return;
        event.preventDefault();
        mobileSearch.open();
    });

    // Hero / catalog mobile search fields become entry points to the modal.
    const bindAppForm = (form) => {
        if (!form.hasAttribute('data-search-mobile-app') || form.dataset.mobileTriggerBound === '1') return;
        form.dataset.mobileTriggerBound = '1';

        // Модал переймає scope і action саме цієї форми, тож підказки й перехід
        // при сабміті лишаються тими самими, що й у десктопному дропдауні форми.
        const formScope = form.dataset.searchScope || 'all';
        const formAction = form.getAttribute('action') || '';
        const openWith = (value) => mobileSearch.open(value, { scope: formScope, action: formAction });

        const openFromForm = (event) => {
            if (!isMobileViewport() || !mobileSearch) return;
            event.preventDefault();
            const value = form.querySelector('input[name="q"]')?.value || '';
            openWith(value);
        };

        const input = form.querySelector('input[name="q"]');
        input?.addEventListener('focus', openFromForm);
        form.addEventListener('submit', (event) => {
            if (!isMobileViewport() || !mobileSearch) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            const value = form.querySelector('input[name="q"]')?.value || '';
            openWith(value);
        });
        form.addEventListener('pointerdown', (event) => {
            if (!isMobileViewport()) return;
            if (!event.target.closest('input[name="q"], button[type="submit"], .hero__mobile-search-field, .reviews-sidebar__search-field')) return;
            event.preventDefault();
            openFromForm(event);
        });
    };

    collectSearchForms(document).forEach(bindAppForm);
};

export const initSearchForms = (root = document) => {
    cleanupDisconnectedDesktopForms();
    collectSearchForms(root).forEach(initDesktopSuggest);
};

mobileSearch = initMobileSearch();
wireMobileTriggers();
initSearchForms(document);
