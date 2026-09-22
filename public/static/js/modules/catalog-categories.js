/* Category / city mega + drill-down filter.
   Desktop: a wide dropdown, portalled to <body> so it escapes the sidebar's
   clipping/overflow and any transformed ancestor, positioned as a fixed layer.
   Mobile: an in-sheet drill-down (list -> subcategories with a back button).
   All interactions are event-delegated on `document`, so the component keeps
   working after the catalog AJAX swaps the filters markup. */
(() => {
    if (!document.body.classList.contains('page-catalog')) return;

    const isSheet = (section) => !!section.closest('.reviews-mobile-filters');
    const isMobile = () => window.matchMedia('(max-width: 760px)').matches;
    // На мобільному будь-який вибір (категорія чи місто) відкривається окремим
    // повноекранним кроком: раніше список розкривався всередині довгого модалу
    // фільтрів, і до підкатегорій доводилось скролити.
    // На десктопі інлайном лишається лише вузький список міст.
    const isInline = (section) => !isMobile() && section.classList.contains('cat-filter--single');

    const getTrigger = (section) => section.querySelector('[data-cat-trigger]');
    const megaOf = (section) => section._catMega || section.querySelector('[data-cat-mega]');
    const sectionOf = (node) => {
        const inline = node.closest('[data-cat-filter]');
        if (inline) return inline;
        const mega = node.closest('[data-cat-mega]');
        return mega ? mega._catSection || null : null;
    };

    let scrollHandler = null;

    const setActiveCat = (section, key, { drill = false } = {}) => {
        const mega = megaOf(section);
        mega.querySelectorAll('[data-cat-cat]').forEach((btn) => {
            const active = btn.dataset.catCat === key;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-expanded', active ? 'true' : 'false');
        });
        mega.querySelectorAll('[data-cat-panel]').forEach((panel) => {
            panel.classList.toggle('is-active', panel.dataset.catPanel === key);
        });
        if (drill) {
            section.classList.add('is-drilled');
            // Випадайка портується в <body>, тому селектор «.cat-filter.is-drilled …»
            // до неї вже не дістає — вішаємо клас і на неї саму.
            mega.classList.add('is-drilled');
        }
    };

    const ensureActivePanel = (section) => {
        const mega = megaOf(section);
        const current = mega.querySelector('[data-cat-cat].is-active') || mega.querySelector('[data-cat-cat]');
        if (current) setActiveCat(section, current.dataset.catCat, { drill: false });
    };

    const positionMega = (section) => {
        const trigger = getTrigger(section);
        const mega = megaOf(section);
        if (!trigger || !mega) return;

        const rect = trigger.getBoundingClientRect();
        const gutter = 12;
        const width = Math.min(640, window.innerWidth - gutter * 2);
        let left = rect.left;
        if (left + width > window.innerWidth - gutter) left = window.innerWidth - gutter - width;
        if (left < gutter) left = gutter;

        mega.style.position = 'fixed';
        mega.style.top = `${Math.round(rect.bottom + 8)}px`;
        mega.style.left = `${Math.round(left)}px`;
        mega.style.width = `${width}px`;
        mega.style.maxHeight = `${Math.round(window.innerHeight - rect.bottom - 24)}px`;
    };

    const portalToBody = (section) => {
        const mega = megaOf(section);
        if (mega._portaled) return;
        const placeholder = document.createComment('cat-mega');
        mega.parentNode.insertBefore(placeholder, mega);
        mega._placeholder = placeholder;
        mega._catSection = section;
        section._catMega = mega;
        document.body.appendChild(mega);
        mega._portaled = true;
    };

    const restoreFromBody = (section) => {
        const mega = section._catMega;
        if (!mega || !mega._portaled) return;
        const placeholder = mega._placeholder;
        if (placeholder && placeholder.parentNode) {
            placeholder.parentNode.insertBefore(mega, placeholder);
            placeholder.remove();
        }
        mega._placeholder = null;
        mega._portaled = false;
    };

    /* --- Пошук усередині випадайки ---------------------------------------
       Довгі списки категорій і міст без пошуку гортати незручно. Фільтруємо
       клієнтськи: у лівій колонці лишаємо групи зі збігом, у правій — усі
       збіги з усіх груп одразу (кожна група підписана своїм заголовком). */
    const norm = (value) => String(value || '')
        .toLowerCase()
        .replace(/[\u02BC\u2019']/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    // --- Пошук усередині випадайки -----------------------------------------
    // Працюємо ЛИШЕ з явно позначеними варіантами ([data-cat-option]) і їхніми
    // назвами з атрибутів, а не з тексту й вкладеності: у тексті є ще й лічильники
    // («Тварини 1184»), а структура різна на десктопі й у мобільному аркуші.
    // Збіг шукаємо за ПОЧАТКОМ слова («тва» → «Тварини», а не «агенТВАтства»),
    // із запасним звичайним входженням, якщо так нічого не знайшлося.
    const startsWord = (text, query) => {
        let i = text.indexOf(query);
        while (i !== -1) {
            if (i === 0 || !/[\p{L}\p{N}]/u.test(text[i - 1])) return true;
            i = text.indexOf(query, i + 1);
        }

        return false;
    };
    const matches = (text, query, strict) => (strict ? startsWord(text, query) : text.includes(query));
    const optionsOf = (mega) => [...mega.querySelectorAll('[data-cat-option], [data-cat-option-all]')];

    const applySearch = (section, rawQuery) => {
        const mega = megaOf(section);
        if (!mega) return;
        const query = norm(rawQuery);
        const searching = query !== '';
        const options = optionsOf(mega);
        const panels = [...mega.querySelectorAll('[data-cat-panel]')];
        const empty = mega.querySelector('[data-cat-empty]');
        const clearBtn = mega.querySelector('[data-cat-search-clear]');

        mega.classList.toggle('is-searching', searching);
        if (clearBtn) clearBtn.hidden = !searching;
        mega.querySelectorAll('.is-highlighted').forEach((el) => el.classList.remove('is-highlighted'));

        if (!searching) {
            options.forEach((el) => { el.hidden = false; });
            panels.forEach((panel) => { panel.hidden = false; });
            if (empty) empty.hidden = true;
            ensureActivePanel(section);

            return;
        }

        const hit = (el, strict) => {
            const group = el.dataset.catOptionGroup ? norm(el.dataset.catOptionGroup) : '';
            if (el.hasAttribute('data-cat-option-all')) {
                // «Усі: {група}» показуємо, коли збіглася сама назва групи;
                // «Усі категорії» / «Усі міста» під час пошуку ховаємо.
                return group !== '' && matches(group, query, strict);
            }
            const own = norm(el.dataset.catOption || '');

            return matches(own, query, strict) || (group !== '' && matches(group, query, strict));
        };

        let found = options.filter((el) => hit(el, true));
        if (found.length === 0) found = options.filter((el) => hit(el, false));

        const visible = new Set(found);
        // Групу в лівій колонці лишаємо видимою, якщо збіг у її дитині.
        const matchedGroups = new Set(found.map((el) => norm(el.dataset.catOptionGroup || '')).filter(Boolean));
        options.forEach((el) => {
            const name = norm(el.dataset.catOption || '');
            const keep = visible.has(el) || (name !== '' && matchedGroups.has(name) && el.hasAttribute('data-cat-cat'));
            el.hidden = !keep;
            if (keep) visible.add(el);
        });

        // Панель показуємо, якщо в ній лишився хоч один варіант.
        const matchedPanels = [];
        panels.forEach((panel) => {
            const any = [...panel.querySelectorAll('[data-cat-option], [data-cat-option-all]')].some((el) => !el.hidden);
            panel.hidden = !any;
            panel.classList.toggle('is-active', any);
            if (any) {
                const groupName = norm(panel.querySelector('[data-cat-option-all]')?.dataset.catOptionGroup || '');
                matchedPanels.push({ panel, groupHit: groupName !== '' && matches(groupName, query, found.length ? true : false) });
            }
        });
        // Групи, чия назва збіглася, — угору списку результатів.
        const subsWrap = mega.querySelector('.cat-filter__subs');
        if (subsWrap) {
            matchedPanels.filter((item) => item.groupHit).reverse()
                .forEach((item) => subsWrap.insertBefore(item.panel, subsWrap.firstChild));
        }

        if (empty) empty.hidden = found.length > 0;
    };

    // Клавіатура: стрілки ходять по видимих варіантах, Enter обирає підсвічений.
    const visibleOptions = (mega) => optionsOf(mega).filter((el) => !el.hidden && el.offsetParent !== null);
    const moveHighlight = (section, step) => {
        const mega = megaOf(section);
        if (!mega) return;
        const list = visibleOptions(mega);
        if (!list.length) return;
        const current = list.findIndex((el) => el.classList.contains('is-highlighted'));
        const next = current === -1 ? (step > 0 ? 0 : list.length - 1) : (current + step + list.length) % list.length;
        list.forEach((el) => el.classList.remove('is-highlighted'));
        list[next].classList.add('is-highlighted');
        list[next].scrollIntoView({ block: 'nearest' });
    };
    const chooseHighlighted = (section) => {
        const mega = megaOf(section);
        if (!mega) return false;
        const target = mega.querySelector('.is-highlighted') || visibleOptions(mega)[0];
        if (!target) return false;
        target.click();

        return true;
    };

    const resetSearch = (section) => {
        const mega = megaOf(section);
        const input = mega ? mega.querySelector('[data-cat-search]') : null;
        if (input) input.value = '';
        applySearch(section, '');
    };

    const openMega = (section) => {
        const mega = megaOf(section);
        const trigger = getTrigger(section);
        if (!mega || !trigger) return;

        document.querySelectorAll('[data-cat-filter].is-open').forEach((other) => {
            if (other !== section) closeMega(other, true);
        });

        mega.hidden = false;
        section.classList.add('is-open');
        trigger.setAttribute('aria-expanded', 'true');
        ensureActivePanel(section);

        if (!isInline(section)) {
            portalToBody(section);
            if (isMobile()) {
                mega.classList.add('is-fullscreen');
                document.body.classList.add('cat-sheet-open');
                mega.scrollTop = 0;
            } else {
                positionMega(section);
                scrollHandler = () => positionMega(section);
                window.addEventListener('scroll', scrollHandler, { passive: true, capture: true });
                window.addEventListener('resize', scrollHandler, { passive: true });
            }
        }

        resetSearch(section);
        window.requestAnimationFrame(() => {
            mega.classList.add('is-visible');
            if (!isSheet(section)) mega.querySelector('[data-cat-search]')?.focus({ preventScroll: true });
        });
    };

    function closeMega(section, immediate = false) {
        const mega = megaOf(section);
        const trigger = getTrigger(section);
        if (!mega || !trigger) return;

        mega.classList.remove('is-visible', 'is-drilled');
        section.classList.remove('is-open', 'is-drilled');
        trigger.setAttribute('aria-expanded', 'false');

        if (scrollHandler) {
            window.removeEventListener('scroll', scrollHandler, { capture: true });
            window.removeEventListener('resize', scrollHandler);
            scrollHandler = null;
        }

        const finish = () => {
            restoreFromBody(section);
            mega.classList.remove('is-fullscreen');
            document.body.classList.remove('cat-sheet-open');
            mega.hidden = true;
            mega.style.cssText = '';
        };

        // On selection the filters markup is about to be swapped — restore the
        // portalled node synchronously so it is never orphaned in <body>.
        if (immediate) {
            finish();
            return;
        }

        window.setTimeout(() => {
            if (section.classList.contains('is-open')) return;
            finish();
        }, 200);
    }

    const closeAll = (immediate = false) => {
        document.querySelectorAll('[data-cat-filter].is-open').forEach((s) => closeMega(s, immediate));
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-cat-trigger]');
        if (trigger) {
            event.preventDefault();
            const section = trigger.closest('[data-cat-filter]');
            if (section.classList.contains('is-open')) closeMega(section);
            else openMega(section);
            return;
        }

        const catBtn = event.target.closest('[data-cat-cat]');
        if (catBtn) {
            event.preventDefault();
            const section = sectionOf(catBtn);
            if (!section) return;
            const drill = isSheet(section) || isMobile();
            setActiveCat(section, catBtn.dataset.catCat, { drill });
            if (drill) {
                // Другий крок завжди починається згори — інакше людина потрапляє
                // в середину списку підкатегорій і мусить скролити вгору.
                const mega = megaOf(section);
                mega?.querySelector('.cat-filter__mega-inner')?.scrollTo?.({ top: 0 });
                if (mega) mega.scrollTop = 0;
            }
            return;
        }

        const sheetClose = event.target.closest('[data-cat-close]');
        if (sheetClose) {
            event.preventDefault();
            const section = sectionOf(sheetClose);
            if (section) closeMega(section);
            return;
        }

        const back = event.target.closest('[data-cat-back]');
        if (back) {
            event.preventDefault();
            const section = sectionOf(back);
            if (!section) return;
            section.classList.remove('is-drilled');
            megaOf(section)?.classList.remove('is-drilled');
            megaOf(section)?.querySelector('.cat-filter__mega-inner')?.scrollTo?.({ top: 0 });
            return;
        }

        // "Усі міста" — clear the city selection.
        const cityClear = event.target.closest('[data-cat-city-clear]');
        if (cityClear) {
            event.preventDefault();
            const section = sectionOf(cityClear);
            if (!section) return;
            const mega = megaOf(section);
            mega?.querySelectorAll('input[name="regions[]"][data-filter-input]').forEach((cb) => { cb.checked = false; });
            // Спершу повертаємо список у форму (на мобільному він повноекранний
            // і живе в <body>), і лише потім сповіщаємо форму — інакше подія
            // приходить із-поза форми, і каталог її не чує.
            closeMega(section, true); // true = повернути список у форму синхронно
            section.querySelector('input[name="regions[]"][data-filter-input]')
                ?.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }

        // Selecting a category/sub link or a city option — the option itself does
        // the filtering (catalog.js); just close the dropdown (it fades out before
        // the debounced results swap).
        const option = event.target.closest('[data-catalog-category-link], .cat-filter__city');
        if (option && option.closest('[data-cat-mega], [data-cat-filter]')) {
            const section = sectionOf(option);
            const isCity = option.classList.contains('cat-filter__city');
            const value = isCity ? option.querySelector('input[data-filter-input]')?.value : null;
            const checked = isCity ? !!option.querySelector('input[data-filter-input]')?.checked : false;
            // immediate = true: інакше список повертається у форму лише через
            // 200 мс, і подія про вибір міста йде з елемента поза формою — каталог
            // її не чує, фільтр не застосовується.
            if (section && section.classList.contains('is-open')) closeMega(section, true);
            if (isCity && section) {
                // Після повернення списку в DOM форми знаходимо ту саму галочку
                // вже всередині форми, виставляємо стан і сповіщаємо каталог.
                const input = [...section.querySelectorAll('input[name="regions[]"][data-filter-input]')]
                    .find((cb) => cb.value === value);
                if (input) {
                    section.querySelectorAll('input[name="regions[]"][data-filter-input]')
                        .forEach((cb) => { if (cb !== input) cb.checked = false; });
                    input.checked = checked;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
            return;
        }

        // Click outside any open dropdown closes it (desktop).
        if (!event.target.closest('[data-cat-filter]') && !event.target.closest('[data-cat-mega]')) {
            closeAll();
        }
    });

    // Desktop: hovering a category reveals its subcategories (like a mega menu).
    document.addEventListener('mouseover', (event) => {
        const catBtn = event.target.closest('[data-cat-cat]');
        if (!catBtn) return;
        const section = sectionOf(catBtn);
        if (!section || isSheet(section)) return;
        setActiveCat(section, catBtn.dataset.catCat, { drill: false });
    });

    document.addEventListener('input', (event) => {
        const input = event.target.closest('[data-cat-search]');
        if (!input) return;
        const section = sectionOf(input);
        if (section) applySearch(section, input.value);
    });

    document.addEventListener('click', (event) => {
        const clear = event.target.closest('[data-cat-search-clear]');
        if (!clear) return;
        event.preventDefault();
        const section = sectionOf(clear);
        if (!section) return;
        resetSearch(section);
        megaOf(section)?.querySelector('[data-cat-search]')?.focus({ preventScroll: true });
    });

    document.addEventListener('keydown', (event) => {
        const input = event.target.closest('[data-cat-search]');
        if (input) {
            // Enter у полі пошуку не має сабмітити форму фільтрів — вибираємо
            // перший знайдений варіант, як у звичних комбобоксах.
            const section = sectionOf(input);
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (section) moveHighlight(section, event.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                if (section) chooseHighlighted(section);
                return;
            }
            if (event.key === 'Escape' && section && norm(input.value) !== '') {
                event.stopPropagation();
                resetSearch(section);
                return;
            }
        }
        if (event.key === 'Escape') closeAll();
    });
})();
