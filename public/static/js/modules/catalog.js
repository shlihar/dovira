(() => {
    if (!document.body.classList.contains('page-catalog')) return;

    const filterForms = Array.from(document.querySelectorAll('[data-catalog-filters-form]'));
    const searchForms = Array.from(document.querySelectorAll('.page-catalog [data-search-form]'));
    const resultsRoot = document.querySelector('[data-catalog-results]');
    const countNode = document.querySelector('[data-catalog-results-count]');
    const statNodes = Array.from(document.querySelectorAll('[data-catalog-stat]'));
    const activeFiltersNode = document.querySelector('[data-catalog-active-filters]');
    const sortSelects = Array.from(document.querySelectorAll('[data-catalog-sort-select]'));
    const viewToggleButtons = Array.from(document.querySelectorAll('[data-catalog-view]'));
    const mobileFiltersToggle = document.querySelector('[data-catalog-open-filters]');
    const mobileFiltersDetails = document.querySelector('#catalog-mobile-filters-panel');
    const mobileFiltersCloseButtons = Array.from(document.querySelectorAll('[data-catalog-close-filters]'));
    const mobileFiltersApplyButton = document.querySelector('[data-catalog-apply-filters]');
    const mobileFiltersResetButton = document.querySelector('[data-catalog-reset-filters]');
    const mobileSortToggle = document.querySelector('[data-catalog-open-sort]');
    const mobileSortDetails = document.querySelector('#catalog-mobile-sort-panel');
    const mobileSortCloseButtons = Array.from(document.querySelectorAll('[data-catalog-close-sort]'));
    const mobileSortOptions = Array.from(document.querySelectorAll('[data-catalog-mobile-sort-option]'));
    const mobileSortLabel = document.querySelector('[data-catalog-mobile-sort-label]');
    if (!filterForms.length || !resultsRoot) return;

    const DEBOUNCE_MS = 220;
    const MAX_AUTO_LOADS = 2;
    let debounceId = 0;
    let controller = null;
    let appendController = null;
    let lastQueryString = '';
    let currentView = window.localStorage.getItem('catalog_view') === 'list' ? 'list' : 'grid';
    let lastRenderedHTML = resultsRoot.innerHTML;
    let catalogInfiniteRoot = null;
    let catalogResultsList = null;
    let catalogLoadCount = null;
    let catalogLoadMoreButton = null;
    let catalogAppendSkeleton = null;
    let catalogLoadSentinel = null;
    let catalogIsAppending = false;
    let catalogAutoLoadsDone = 0;
    let catalogAutoLoadArmed = false;
    let catalogLoadObserver = null;
    const catalogShowMoreState = new Map();

    const applyAvatarGradients = (root) => {
        const logos = root.querySelectorAll('.review-list-card__logo.has-random-gradient[data-seed]');
        if (!logos.length) return;
        const hash = (input) => {
            let value = 0;
            for (let i = 0; i < input.length; i += 1) {
                value = (value << 5) - value + input.charCodeAt(i);
                value |= 0;
            }
            return Math.abs(value);
        };
        const palettes = [
            ['#eaf2ff', '#1f68ff'],
            ['#e8f5ff', '#0f4fb8'],
            ['#edf0ff', '#3b5bff'],
            ['#eefcff', '#1483ff'],
            ['#e8fffb', '#0d86c6'],
        ];
        logos.forEach((logo) => {
            const seed = logo.dataset.seed ?? '';
            const [bg1, bg2] = palettes[hash(seed) % palettes.length];
            logo.style.background = `linear-gradient(135deg, ${bg1}, ${bg2})`;
        });
    };

    const getMainSearchValue = () => {
        const desktopInput = document.querySelector('.reviews-primary-search.reviews-sidebar__search-wrap input[name=\"q\"]');
        const mobileInput = document.querySelector('.reviews-mobile-search input[name=\"q\"]');
        return (desktopInput?.value ?? mobileInput?.value ?? '').trim();
    };

    const syncSearchInputs = (value, source) => {
        searchForms.forEach((form) => {
            if (form === source) return;
            const input = form.querySelector('input[name=\"q\"]');
            if (input) input.value = value;
        });
    };

    const getFilterBodies = () => Array.from(document.querySelectorAll('[data-catalog-filters-body]'));

    const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const findScrollParent = (element) => {
        let current = element?.parentElement || null;
        while (current && current !== document.body) {
            const style = window.getComputedStyle(current);
            const overflowY = style.overflowY;
            const isScrollable = (overflowY === 'auto' || overflowY === 'scroll') && current.scrollHeight > current.clientHeight;
            if (isScrollable) return current;
            current = current.parentElement;
        }
        return null;
    };

    const animateShowMoreList = (list, hiddenItems, expand, scope) => {
        if (!list || !hiddenItems.length || list.dataset.animating === '1') return;
        list.dataset.animating = '1';

        const DURATION = 240;
        const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';
        const scrollContainer = scope?.closest('.reviews-sidebar') || findScrollParent(scope);
        let followRaf = 0;

        const followScroll = () => {
            if (list.dataset.animating !== '1' || !scope) return;
            const rect = scope.getBoundingClientRect();

            if (scrollContainer) {
                const containerRect = scrollContainer.getBoundingClientRect();
                const safeBottom = containerRect.bottom - 10;
                const overflowBottom = rect.bottom - safeBottom;
                if (overflowBottom > 0) {
                    scrollContainer.scrollTop += Math.min(overflowBottom, 10);
                }

                if (!expand) {
                    const safeTop = containerRect.top + 10;
                    const overflowTop = safeTop - rect.top;
                    if (overflowTop > 0) {
                        scrollContainer.scrollTop -= Math.min(overflowTop, 10);
                    }
                }
            } else {
                const safeBottom = window.innerHeight - 16;
                const overflowBottom = rect.bottom - safeBottom;
                if (overflowBottom > 0) {
                    window.scrollBy(0, Math.min(overflowBottom, 10));
                }

                if (!expand) {
                    const safeTop = 96;
                    const overflowTop = safeTop - rect.top;
                    if (overflowTop > 0) {
                        window.scrollBy(0, -Math.min(overflowTop, 8));
                    }
                }
            }

            followRaf = window.requestAnimationFrame(followScroll);
        };

        const clear = () => {
            if (followRaf) window.cancelAnimationFrame(followRaf);
            list.style.height = '';
            list.style.overflow = '';
            list.style.transition = '';
            list.dataset.animating = '0';
            list.removeEventListener('transitionend', onEnd);
        };

        const onEnd = (event) => {
            if (event.target !== list || event.propertyName !== 'height') return;
            clear();
        };

        list.style.overflow = 'hidden';
        list.style.transition = `height ${DURATION}ms ${EASE}`;

        if (expand) {
            const start = list.scrollHeight;
            hiddenItems.forEach((item) => {
                item.hidden = false;
            });
            const end = list.scrollHeight;
            list.style.height = `${start}px`;
            void list.offsetHeight;
            list.style.height = `${end}px`;
            list.addEventListener('transitionend', onEnd);
            followRaf = window.requestAnimationFrame(followScroll);
            return;
        }

        const start = list.scrollHeight;
        hiddenItems.forEach((item) => {
            item.hidden = true;
        });
        const end = list.scrollHeight;
        hiddenItems.forEach((item) => {
            item.hidden = false;
        });
        list.style.height = `${start}px`;
        void list.offsetHeight;
        window.requestAnimationFrame(() => {
            hiddenItems.forEach((item) => {
                item.hidden = true;
            });
            list.style.height = `${end}px`;
            list.addEventListener('transitionend', onEnd);
            followRaf = window.requestAnimationFrame(followScroll);
        });
    };

    const bindCatalogShowMoreToggle = (toggle) => {
        if (!(toggle instanceof HTMLElement) || toggle.dataset.catalogShowMoreBound === '1') return;

        const key = toggle.getAttribute('data-show-more-toggle');
        const scope = toggle.closest('.minimal-filter-group');
        const list = scope?.querySelector(`[data-show-more-list="${key}"]`);
        if (!list) {
            toggle.hidden = true;
            return;
        }

        const hiddenItems = Array.from(list.querySelectorAll('[data-show-more-item]'));
        if (!hiddenItems.length) {
            toggle.hidden = true;
            return;
        }

        toggle.dataset.catalogShowMoreBound = '1';
        if (!catalogShowMoreState.has(key)) {
            catalogShowMoreState.set(key, toggle.getAttribute('aria-expanded') === 'true');
        }

        const applyShowMoreState = (expanded) => {
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.innerHTML = `${expanded ? 'Згорнути' : 'Показати всі'} <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>`;
            hiddenItems.forEach((item) => {
                item.hidden = !expanded;
            });
        };

        applyShowMoreState(catalogShowMoreState.get(key) === true);

        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            const next = !expanded;
            catalogShowMoreState.set(key, next);
            toggle.setAttribute('aria-expanded', next ? 'true' : 'false');
            toggle.innerHTML = `${next ? 'Згорнути' : 'Показати всі'} <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>`;
            animateShowMoreList(list, hiddenItems, next, scope);
        });
    };

    const initCatalogShowMoreToggles = (root) => {
        if (!(root instanceof Element)) return;
        root.querySelectorAll('[data-show-more-toggle]').forEach(bindCatalogShowMoreToggle);
    };

    const syncFilterForms = (sourceForm) => {
        const sourceInputs = Array.from(sourceForm.querySelectorAll('[data-filter-input]'));
        const sourceState = new Map();
        sourceInputs.forEach((input) => {
            const isCheckable = input.type === 'checkbox' || input.type === 'radio';
            if (isCheckable) {
                sourceState.set(`${input.name}::${input.value}`, { checked: input.checked });
                return;
            }
            sourceState.set(input.name, { value: input.value });
        });

        filterForms.forEach((form) => {
            if (form === sourceForm) return;
            form.querySelectorAll('[data-filter-input]').forEach((input) => {
                const isCheckable = input.type === 'checkbox' || input.type === 'radio';
                if (isCheckable) {
                    const key = `${input.name}::${input.value}`;
                    if (sourceState.has(key)) {
                        input.checked = sourceState.get(key)?.checked === true;
                    }
                    return;
                }
                if (sourceState.has(input.name)) {
                    input.value = sourceState.get(input.name)?.value ?? input.value;
                }
            });
        });
    };

    const collectArrayParam = (params, baseName) => {
        const values = [];
        params.forEach((value, key) => {
            if (key === baseName || key === `${baseName}[]` || key.match(new RegExp(`^${baseName}\\\\[\\\\d*\\\\]$`))) {
                values.push(String(value).toLowerCase());
            }
        });
        return new Set(values);
    };

    const getCurrentCatalogParams = () => collectParams(filterForms[0]);

    const removeArrayParamValue = (params, baseName, targetValue) => {
        const normalizedTarget = String(targetValue || '').toLowerCase();
        const next = new URLSearchParams();
        params.forEach((value, key) => {
            const isArrayKey = key === baseName || key === `${baseName}[]` || key.match(new RegExp(`^${baseName}\\\\[\\\\d*\\\\]$`));
            if (isArrayKey && String(value || '').toLowerCase() === normalizedTarget) return;
            next.append(key, value);
        });
        return next;
    };

    const hasArrayParamValue = (params, baseName, value) => {
        return collectArrayParam(params, baseName).has(String(value || '').toLowerCase());
    };

    const sheetCloseTimers = new WeakMap();

    // Lock the page behind the mobile filter/sort bottom-sheet so it behaves like
    // a real modal — pinned to the viewport, with the background frozen (this also
    // fixes iOS keeping `position: fixed` stable instead of drifting into content).
    let sheetLockedScrollY = 0;
    let sheetScrollLocked = false;

    const lockSheetScroll = () => {
        if (sheetScrollLocked) return;
        sheetLockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${sheetLockedScrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        sheetScrollLocked = true;
    };

    const unlockSheetScroll = () => {
        if (!sheetScrollLocked) return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        sheetScrollLocked = false;
        const prev = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, sheetLockedScrollY);
        document.documentElement.style.scrollBehavior = prev;
    };

    const otherSheetActive = (details) => {
        const other = details === mobileFiltersDetails ? mobileSortDetails : mobileFiltersDetails;
        return !!other && (other.open || other.classList.contains('is-closing'));
    };

    // Bottom-sheet <details> hides its content instantly when `open` is removed,
    // which kills the slide-down exit. Keep it open, play an `.is-closing`
    // transition, then remove `open` once the animation has finished.
    const setSheetOpen = (details, toggle, open) => {
        if (!details || !toggle) return;

        const pending = sheetCloseTimers.get(details);
        if (pending) {
            window.clearTimeout(pending);
            sheetCloseTimers.delete(details);
        }

        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('is-active', open);

        if (open) {
            details.classList.remove('is-closing');
            details.open = true;
            lockSheetScroll();
            return;
        }

        const finishClose = () => {
            details.open = false;
            details.classList.remove('is-closing');
            if (!otherSheetActive(details)) unlockSheetScroll();
        };

        if (!details.open || prefersReducedMotion()) {
            details.classList.remove('is-closing');
            finishClose();
            return;
        }

        details.classList.add('is-closing');
        const timer = window.setTimeout(() => {
            finishClose();
            sheetCloseTimers.delete(details);
        }, 280);
        sheetCloseTimers.set(details, timer);
    };

    const setMobileFiltersOpen = (open) => {
        if (!mobileFiltersDetails || !mobileFiltersToggle) return;
        if (open) setMobileSortOpen(false);
        setSheetOpen(mobileFiltersDetails, mobileFiltersToggle, open);
    };

    const setMobileSortOpen = (open) => {
        if (!mobileSortDetails || !mobileSortToggle) return;
        if (open) setMobileFiltersOpen(false);
        setSheetOpen(mobileSortDetails, mobileSortToggle, open);
    };

    const updateMobileSortState = (sort) => {
        const normalized = String(sort || 'recommended');
        const activeOption = mobileSortOptions.find((option) => option.dataset.sortValue === normalized) || mobileSortOptions[0];
        mobileSortOptions.forEach((option) => {
            option.classList.toggle('is-active', option === activeOption);
        });
        if (mobileSortLabel && activeOption) {
            mobileSortLabel.textContent = activeOption.querySelector('span')?.textContent?.trim() || 'Сортування';
        }
    };

    const setActiveSort = (sort) => {
        sortSelects.forEach((select) => {
            select.value = sort;
        });
        filterForms.forEach((form) => {
            const sortInput = form.querySelector('[data-catalog-sort-input]');
            if (sortInput) {
                sortInput.value = sort;
            }
        });
    };

    const replaceFiltersMarkup = (html) => {
        if (typeof html !== 'string') return;

        const bodies = getFilterBodies();
        bodies.forEach((body) => {
            body.classList.add('is-refreshing');
        });

        window.setTimeout(() => {
            bodies.forEach((body) => {
                body.innerHTML = html;
                initCatalogShowMoreToggles(body);
                window.requestAnimationFrame(() => {
                    body.classList.remove('is-refreshing');
                });
            });
            setActiveSort(sortSelects[0]?.value || 'recommended');
        }, 80);
    };

    const initialSort = sortSelects[0]?.value || 'recommended';
    setActiveSort(initialSort);
    updateMobileSortState(initialSort);

    const singleChoiceFilterNames = new Set(['rating', 'reviews_count', 'status', 'regions[]']);

    const enforceSingleChoiceCheckbox = (input, form) => {
        if (input.type !== 'checkbox' || !singleChoiceFilterNames.has(input.name) || !input.checked) return;

        form.querySelectorAll('input[type="checkbox"][data-filter-input]').forEach((candidate) => {
            if (candidate !== input && candidate.name === input.name) {
                candidate.checked = false;
            }
        });
    };

    const collectParams = (sourceForm) => {
        const params = new URLSearchParams();
        const q = getMainSearchValue();
        if (q) params.set('q', q);

        const formData = new FormData(sourceForm);
        formData.forEach((value, key) => {
            const raw = String(value).trim();
            if (!raw) return;
            params.append(key, raw);
        });

        return params;
    };

    const normalizeQueryString = (input) => {
        const params = new URLSearchParams(typeof input === 'string' ? input.replace(/^\?/, '') : '');
        params.delete('ajax');
        return params.toString();
    };

    const applyQueryToControls = (queryString) => {
        const params = new URLSearchParams(queryString);
        const categories = collectArrayParam(params, 'categories');
        const regions = collectArrayParam(params, 'regions');
        const sub = (params.get('sub') || '').toLowerCase();
        if (sub) categories.add(sub);

        syncSearchInputs((params.get('q') || '').trim(), null);
        setActiveSort(params.get('sort') || 'recommended');
        updateMobileSortState(params.get('sort') || 'recommended');

        filterForms.forEach((form) => {
            form.querySelectorAll('[data-filter-input]').forEach((input) => {
                const isCheckable = input.type === 'checkbox' || input.type === 'radio';
                if (!isCheckable) {
                    if (input.name !== 'sort') {
                        input.value = params.get(input.name) || '';
                    }
                    return;
                }

                const value = String(input.value || '').toLowerCase();
                if (input.name === 'categories[]') {
                    input.checked = categories.has(value);
                    return;
                }
                if (input.name === 'regions[]') {
                    input.checked = regions.has(value);
                    return;
                }

                input.checked = String(params.get(input.name) || '') === String(input.value || '');
            });
        });
    };

    const renderCount = (count) => {
        if (!countNode || !count) return;
        if (typeof count.label === 'string' && count.label.trim()) {
            countNode.textContent = count.label.trim();
            return;
        }
        const from = Number(count.from ?? 0);
        const to = Number(count.to ?? 0);
        const total = Number(count.total ?? 0);
        countNode.textContent = `${from}–${to} з ${total} результатів`;
    };

    const formatStat = (value, suffix = '') => `${Number(value ?? 0).toLocaleString('uk-UA')}${suffix}`;

    const renderStats = (stats) => {
        if (!stats || !statNodes.length) return;

        statNodes.forEach((node) => {
            const key = node.dataset.catalogStat;
            if (!key || !Object.prototype.hasOwnProperty.call(stats, key)) return;
            node.textContent = formatStat(stats[key], key === 'verified' ? '' : '+');
        });
    };

    const renderActiveFilters = (filters) => {
        if (!activeFiltersNode || !Array.isArray(filters)) return;
        activeFiltersNode.classList.add('is-updating');
        activeFiltersNode.innerHTML = '';
        activeFiltersNode.hidden = filters.length === 0;
        if (!filters.length) {
            window.requestAnimationFrame(() => activeFiltersNode.classList.remove('is-updating'));
            return;
        }

        filters.forEach((filter) => {
            const query = new URLSearchParams(filter.query ?? {});
            const link = document.createElement('a');
            link.className = 'catalog-active-filter';
            link.href = query.toString() ? `/catalog?${query.toString()}` : '/catalog';
            link.dataset.catalogFilterChip = '1';
            link.dataset.noPageSkeleton = '1';
            link.innerHTML = `<span></span><i class="fa-solid fa-xmark" aria-hidden="true"></i>`;
            link.querySelector('span').textContent = filter.label ?? '';
            activeFiltersNode.appendChild(link);
        });

        const clearLink = document.createElement('a');
        clearLink.className = 'catalog-active-filters__clear';
        clearLink.href = '/catalog';
        clearLink.dataset.noPageSkeleton = '1';
        clearLink.textContent = 'Очистити все';
        activeFiltersNode.appendChild(clearLink);
        window.requestAnimationFrame(() => activeFiltersNode.classList.remove('is-updating'));
    };

    const applyViewMode = () => {
        const list = resultsRoot.querySelector('[data-catalog-results-list]');
        const appendSkeleton = resultsRoot.querySelector('[data-catalog-append-skeleton]');
        if (list) {
            list.classList.toggle('is-grid', currentView === 'grid');
        }
        if (appendSkeleton) {
            appendSkeleton.classList.toggle('catalog-results-append-skeleton--grid', currentView === 'grid');
        }
        viewToggleButtons.forEach((button) => {
            const active = button.dataset.catalogView === currentView;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    };

    const syncCatalogInfiniteElements = () => {
        catalogInfiniteRoot = resultsRoot.querySelector('[data-catalog-infinite]');
        catalogResultsList = resultsRoot.querySelector('[data-catalog-results-list]');
        catalogLoadCount = resultsRoot.querySelector('[data-catalog-infinite-count]');
        catalogLoadMoreButton = resultsRoot.querySelector('[data-catalog-load-more]');
        catalogAppendSkeleton = resultsRoot.querySelector('[data-catalog-append-skeleton]');
        catalogLoadSentinel = resultsRoot.querySelector('[data-catalog-load-sentinel]');
    };

    const updateCatalogLoadbar = () => {
        if (!catalogInfiniteRoot) return;

        const loadedCount = Math.max(0, Number(catalogInfiniteRoot.dataset.loadedCount || 0));
        const totalCountValue = String(catalogInfiniteRoot.dataset.totalCount || '').trim();
        const totalCount = totalCountValue !== '' ? Math.max(loadedCount, Number(totalCountValue || 0)) : null;
        const hasMore = catalogInfiniteRoot.dataset.hasMore === '1';

        if (catalogLoadCount) {
            if (loadedCount === 0) {
                catalogLoadCount.textContent = 'Нічого не знайдено';
            } else if (totalCount !== null && Number.isFinite(totalCount) && totalCount > 0) {
                catalogLoadCount.textContent = `Показано ${loadedCount} з ${totalCount}`;
            } else {
                catalogLoadCount.textContent = `Показано ${loadedCount}${hasMore ? '+' : ''} профілів`;
            }
        }

        if (catalogLoadMoreButton) {
            catalogLoadMoreButton.classList.toggle('is-hidden', !hasMore);
            catalogLoadMoreButton.disabled = catalogIsAppending;
        }
    };

    const setCatalogAppendSkeletonVisible = (visible) => {
        if (!catalogAppendSkeleton) return;
        catalogAppendSkeleton.classList.toggle('is-hidden', !visible);
    };

    const disconnectCatalogObserver = () => {
        if (catalogLoadObserver) {
            catalogLoadObserver.disconnect();
            catalogLoadObserver = null;
        }
    };

    const initCatalogInfiniteScroll = () => {
        disconnectCatalogObserver();
        syncCatalogInfiniteElements();
        catalogIsAppending = false;
        catalogAutoLoadsDone = 0;
        catalogAutoLoadArmed = false;
        setCatalogAppendSkeletonVisible(false);
        updateCatalogLoadbar();

        if (!catalogLoadSentinel || !catalogInfiniteRoot || !('IntersectionObserver' in window)) return;

        catalogLoadObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                if (!catalogAutoLoadArmed) return;
                if (catalogAutoLoadsDone >= MAX_AUTO_LOADS) return;
                catalogAutoLoadArmed = false;
                appendCatalogResults({ auto: true });
            });
        }, {
            root: null,
            threshold: 0.35,
            rootMargin: '0px 0px 120px 0px',
        });
        catalogLoadObserver.observe(catalogLoadSentinel);
    };

    const buildCatalogSkeletonMarkup = () => {
        const cardsCount = currentView === 'grid' ? 6 : 4;
        const cards = Array.from({ length: cardsCount }).map(() => `
            <article class="catalog-skeleton-card" aria-hidden="true">
                <div class="catalog-skeleton-card__head">
                    <span class="catalog-skeleton-card__avatar"></span>
                    <div class="catalog-skeleton-card__meta">
                        <span class="catalog-skeleton-card__line line-lg"></span>
                        <span class="catalog-skeleton-card__line line-md"></span>
                    </div>
                </div>
                <div class="catalog-skeleton-card__rating">
                    <span class="catalog-skeleton-card__line line-xs"></span>
                    <span class="catalog-skeleton-card__line line-sm"></span>
                </div>
                <div class="catalog-skeleton-card__body">
                    <span class="catalog-skeleton-card__line line-md"></span>
                    <span class="catalog-skeleton-card__line line-lg"></span>
                    <span class="catalog-skeleton-card__line line-full"></span>
                    <span class="catalog-skeleton-card__line line-mid"></span>
                </div>
            </article>
        `).join('');

        return `<div class="catalog-results-skeleton ${currentView === 'grid' ? 'catalog-results-skeleton--grid' : ''}" data-catalog-results-skeleton>${cards}</div>`;
    };

    const fetchResults = async (sourceForm, options = {}) => {
        const queryString = typeof options.queryString === 'string'
            ? normalizeQueryString(options.queryString)
            : collectParams(sourceForm).toString();
        if (!options.force && queryString === lastQueryString && !resultsRoot.classList.contains('is-loading')) return;
        lastQueryString = queryString;

        const requestParams = new URLSearchParams(queryString);
        requestParams.set('ajax', '1');

        if (controller) controller.abort();
        if (appendController) {
            appendController.abort();
            appendController = null;
        }
        controller = new AbortController();
        disconnectCatalogObserver();
        catalogIsAppending = false;

        resultsRoot.classList.add('is-loading');

        // popstate по ЧПУ-URL (/catalog/advokaty…): фетчимо сам URL — path теж
        // віддає ajax-партіал (landing → index), тож категорія з path не губиться.
        const fetchTarget = options.absoluteUrl
            ? `${options.absoluteUrl}${options.absoluteUrl.includes('?') ? '&' : '?'}ajax=1`
            : `/catalog?${requestParams.toString()}`;

        try {
            const response = await fetch(fetchTarget, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (typeof payload.html === 'string') {
                resultsRoot.innerHTML = payload.html;
                lastRenderedHTML = payload.html;
                applyAvatarGradients(resultsRoot);
                window.DoviraFitCardTags?.(resultsRoot);
                applyViewMode();
                initCatalogInfiniteScroll();
            }
            replaceFiltersMarkup(payload.filtersHtml);
            renderCount(payload.count);
            renderStats(payload.stats);
            renderActiveFilters(payload.activeFilters);
            // Синхронізуємо шапку (H1/крихти/опис) зі станом фільтрів, інакше
            // заголовок лишається від первинного заходу (напр. «Адвокати», хоча
            // фільтр уже «Косметологічні клініки»).
            if (typeof payload.heroHtml === 'string') {
                const heroCopy = document.querySelector('[data-catalog-hero-copy]');
                if (heroCopy) {
                    heroCopy.innerHTML = payload.heroHtml;
                    const newH1 = heroCopy.querySelector('h1');
                    if (newH1) document.title = `${newH1.textContent.trim()} — DOVIRA`;
                }
            }
            // ЧПУ-URL для показу дає сервер (мапінг назва→slug там). Фолбек —
            // звичайний query-URL. На popstate URL уже правильний — не чіпаємо.
            const url = (typeof payload.url === 'string' && payload.url)
                ? payload.url
                : (queryString ? `/catalog?${queryString}` : '/catalog');
            if (options.skipPush) {
                // popstate: адреса вже виставлена браузером
            } else if (options.pushHistory) {
                window.history.pushState({}, '', url);
            } else {
                window.history.replaceState({}, '', url);
            }
            updateMobileSortState(requestParams.get('sort') || 'recommended');

            if (options.scrollToResults) {
                resultsRoot.scrollIntoView({
                    behavior: prefersReducedMotion() ? 'auto' : 'smooth',
                    block: 'start',
                });
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                console.error(error);
                if (lastRenderedHTML) {
                    resultsRoot.innerHTML = lastRenderedHTML;
                    applyAvatarGradients(resultsRoot);
                    window.DoviraFitCardTags?.(resultsRoot);
                    applyViewMode();
                    initCatalogInfiniteScroll();
                }
            }
        } finally {
            resultsRoot.classList.remove('is-loading');
        }
    };

    const appendCatalogResults = async ({ auto = false } = {}) => {
        if (catalogIsAppending || !catalogInfiniteRoot || !catalogResultsList) return;

        const nextUrl = String(catalogInfiniteRoot.dataset.nextUrl || '').trim();
        const hasMore = catalogInfiniteRoot.dataset.hasMore === '1';

        if (!nextUrl || !hasMore) return;
        if (auto && catalogAutoLoadsDone >= MAX_AUTO_LOADS) return;

        const requestUrl = new URL(nextUrl, window.location.origin);
        requestUrl.searchParams.set('ajax', '1');
        requestUrl.searchParams.set('append', '1');

        catalogIsAppending = true;
        appendController = new AbortController();
        if (catalogLoadMoreButton) {
            catalogLoadMoreButton.textContent = 'Завантаження...';
        }
        setCatalogAppendSkeletonVisible(true);
        updateCatalogLoadbar();

        try {
            const response = await fetch(requestUrl.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: appendController.signal,
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            const html = typeof payload.html === 'string' ? payload.html.trim() : '';
            if (html !== '') {
                catalogResultsList.insertAdjacentHTML('beforeend', html);
                applyAvatarGradients(catalogResultsList);
                window.DoviraFitCardTags?.(catalogResultsList);
            }

            const loadedCount = Math.max(
                Number(catalogInfiniteRoot.dataset.loadedCount || 0),
                Number(payload.loadedCount || 0)
            );
            catalogInfiniteRoot.dataset.loadedCount = String(loadedCount);
            catalogInfiniteRoot.dataset.hasMore = payload.hasMore ? '1' : '0';
            catalogInfiniteRoot.dataset.nextUrl = String(payload.nextUrl || '');
            catalogInfiniteRoot.dataset.currentPage = String(payload.currentPage || catalogInfiniteRoot.dataset.currentPage || '1');

            if (payload.count) {
                renderCount(payload.count);
            }
            if (payload.stats) {
                renderStats(payload.stats);
            }
            if (Array.isArray(payload.activeFilters)) {
                renderActiveFilters(payload.activeFilters);
            }

            if (auto) {
                catalogAutoLoadsDone += 1;
            }
        } catch (error) {
            if (error?.name !== 'AbortError') {
                console.error('Failed to append catalog results', error);
            }
        } finally {
            catalogIsAppending = false;
            appendController = null;
            setCatalogAppendSkeletonVisible(false);
            if (catalogLoadMoreButton) {
                catalogLoadMoreButton.textContent = 'Показати ще';
            }
            updateCatalogLoadbar();
        }
    };

    const scheduleFetch = (sourceForm, options = {}) => {
        if (debounceId) window.clearTimeout(debounceId);
        debounceId = window.setTimeout(() => {
            fetchResults(sourceForm, options);
        }, DEBOUNCE_MS);
    };

    const resetAllCatalogFilters = () => {
        const resetParams = new URLSearchParams();
        resetParams.set('sort', 'recommended');
        applyQueryToControls(resetParams.toString());
        syncFilterForms(filterForms[0]);
        // Force refresh even when previous query matches post-reset state.
        lastQueryString = '';
        fetchResults(filterForms[0]);
    };

    filterForms.forEach((form) => {
        form.addEventListener('change', (event) => {
            const input = event.target.closest('[data-filter-input]');
            if (!input) return;
            enforceSingleChoiceCheckbox(input, form);
            syncFilterForms(form);
            scheduleFetch(form);
        });
    });

    searchForms.forEach((form) => {
        const input = form.querySelector('input[name=\"q\"]');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const sourceForm = filterForms[0];
            syncSearchInputs((input?.value ?? '').trim(), form);
            fetchResults(sourceForm);
        });
        input?.addEventListener('input', () => {
            syncSearchInputs((input.value ?? '').trim(), form);
            scheduleFetch(filterForms[0]);
        });
    });

    sortSelects.forEach((select) => {
        select.addEventListener('change', () => {
            const sort = select.value || 'recommended';
            setActiveSort(sort);
            syncFilterForms(filterForms[0]);
            fetchResults(filterForms[0]);
        });
    });

    document.addEventListener('click', (event) => {
        const categoryLink = event.target.closest('[data-catalog-category-link]');
        // The category dropdown is portalled to <body>, so its links live outside
        // the filter form — accept those too, otherwise the <a> would full-reload.
        if (categoryLink && (filterForms.some((form) => form.contains(categoryLink)) || categoryLink.closest('[data-cat-mega]'))) {
            event.preventDefault();
            const url = new URL(categoryLink.href, window.location.origin);
            applyQueryToControls(url.search);
            syncFilterForms(filterForms[0]);
            // Debounced so the category dropdown can animate closed before the
            // filters markup is swapped (smooth on mobile, matches other filters).
            // pushHistory: вибір категорії — це «навігація» на ЧПУ-URL, тож
            // створюємо запис історії (кнопка «назад» повертає до попереднього).
            scheduleFetch(filterForms[0], { pushHistory: true });
            return;
        }

        const link = event.target.closest('[data-catalog-filter-chip], .catalog-active-filters__clear');
        if (!link || !activeFiltersNode?.contains(link)) return;

        event.preventDefault();
        window.DoviraPageSkeleton?.hide?.();

        if (link.classList.contains('catalog-active-filters__clear')) {
            resetAllCatalogFilters();
            return;
        }

        link.classList.add('is-removing');

        window.setTimeout(() => {
            const url = new URL(link.href, window.location.origin);
            applyQueryToControls(url.search);
            fetchResults(filterForms[0]);
        }, 180);
    });

    document.addEventListener('click', (event) => {
        const loadMoreButton = event.target.closest('[data-catalog-load-more]');
        if (!loadMoreButton || !resultsRoot.contains(loadMoreButton)) return;

        event.preventDefault();
        appendCatalogResults({ auto: false });
    });


    viewToggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const nextView = button.dataset.catalogView;
            if (!nextView || (nextView !== 'list' && nextView !== 'grid')) return;
            currentView = nextView;
            window.localStorage.setItem('catalog_view', currentView);
            applyViewMode();
            // Card widths change between grid and list — re-measure how many
            // direction chips fit per row.
            window.DoviraFitCardTags?.(resultsRoot);
        });
    });

    if (mobileFiltersToggle && mobileFiltersDetails) {
        mobileFiltersToggle.addEventListener('click', (event) => {
            event.preventDefault();
            setMobileFiltersOpen(!mobileFiltersDetails.open);
        });

        mobileFiltersCloseButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                setMobileFiltersOpen(false);
            });
        });

        mobileFiltersApplyButton?.addEventListener('click', (event) => {
            event.preventDefault();
            setMobileFiltersOpen(false);
        });

        mobileFiltersResetButton?.addEventListener('click', (event) => {
            event.preventDefault();
            resetAllCatalogFilters();
            setMobileFiltersOpen(false);
        });

        document.addEventListener('click', (event) => {
            if (!mobileFiltersDetails.open) return;
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (mobileFiltersDetails.contains(target) || mobileFiltersToggle.contains(target)) return;
            setMobileFiltersOpen(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !mobileFiltersDetails.open) return;
            setMobileFiltersOpen(false);
        });
    }

    if (mobileSortToggle && mobileSortDetails) {
        mobileSortToggle.addEventListener('click', (event) => {
            event.preventDefault();
            setMobileSortOpen(!mobileSortDetails.open);
        });

        mobileSortCloseButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                setMobileSortOpen(false);
            });
        });

        mobileSortOptions.forEach((button) => {
            button.addEventListener('click', () => {
                const sort = button.dataset.sortValue || 'recommended';
                setActiveSort(sort);
                updateMobileSortState(sort);
                syncFilterForms(filterForms[0]);
                setMobileSortOpen(false);
                lastQueryString = '';
                fetchResults(filterForms[0]);
            });
        });

        document.addEventListener('click', (event) => {
            if (!mobileSortDetails.open) return;
            const target = event.target;
            if (!(target instanceof Element)) return;
            if (mobileSortDetails.contains(target) || mobileSortToggle.contains(target)) return;
            setMobileSortOpen(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || !mobileSortDetails.open) return;
            setMobileSortOpen(false);
        });
    }

    window.addEventListener('popstate', () => {
        // sort/rating/пошук — із query; категорію/місто несе path, тож фетчимо
        // сам URL (сайдбар синкнеться з filtersHtml у відповіді).
        applyQueryToControls(window.location.search);
        fetchResults(filterForms[0], {
            absoluteUrl: window.location.pathname + window.location.search,
            force: true,
            skipPush: true,
        });
    });

    const armCatalogAutoLoad = () => {
        catalogAutoLoadArmed = true;
    };

    window.addEventListener('wheel', armCatalogAutoLoad, { passive: true });
    window.addEventListener('touchmove', armCatalogAutoLoad, { passive: true });
    window.addEventListener('scroll', armCatalogAutoLoad, { passive: true });

    applyAvatarGradients(document);
    window.DoviraFitCardTags?.(document);
    applyQueryToControls(window.location.search);
    applyViewMode();
    updateMobileSortState(initialSort);
    initCatalogInfiniteScroll();
})();
