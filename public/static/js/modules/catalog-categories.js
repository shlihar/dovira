/* Category / city mega + drill-down filter.
   Desktop: a wide dropdown, portalled to <body> so it escapes the sidebar's
   clipping/overflow and any transformed ancestor, positioned as a fixed layer.
   Mobile: an in-sheet drill-down (list -> subcategories with a back button).
   All interactions are event-delegated on `document`, so the component keeps
   working after the catalog AJAX swaps the filters markup. */
(() => {
    if (!document.body.classList.contains('page-catalog')) return;

    const isSheet = (section) => !!section.closest('.reviews-mobile-filters');
    // Single-column filters (cities) stay inline; only the wide two-pane category
    // dropdown is portalled to <body> to escape the sidebar's clipping.
    const isInline = (section) => isSheet(section) || section.classList.contains('cat-filter--single');

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
        if (drill) section.classList.add('is-drilled');
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
            positionMega(section);
            scrollHandler = () => positionMega(section);
            window.addEventListener('scroll', scrollHandler, { passive: true, capture: true });
            window.addEventListener('resize', scrollHandler, { passive: true });
        }

        window.requestAnimationFrame(() => mega.classList.add('is-visible'));
    };

    function closeMega(section, immediate = false) {
        const mega = megaOf(section);
        const trigger = getTrigger(section);
        if (!mega || !trigger) return;

        mega.classList.remove('is-visible');
        section.classList.remove('is-open', 'is-drilled');
        trigger.setAttribute('aria-expanded', 'false');

        if (scrollHandler) {
            window.removeEventListener('scroll', scrollHandler, { capture: true });
            window.removeEventListener('resize', scrollHandler);
            scrollHandler = null;
        }

        const finish = () => {
            restoreFromBody(section);
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
            if (section) setActiveCat(section, catBtn.dataset.catCat, { drill: isSheet(section) });
            return;
        }

        const back = event.target.closest('[data-cat-back]');
        if (back) {
            event.preventDefault();
            sectionOf(back)?.classList.remove('is-drilled');
            return;
        }

        // "Усі міста" — clear the city selection.
        const cityClear = event.target.closest('[data-cat-city-clear]');
        if (cityClear) {
            event.preventDefault();
            const section = sectionOf(cityClear);
            if (section) {
                section.querySelectorAll('input[name="regions[]"][data-filter-input]').forEach((cb) => { cb.checked = false; });
                section.querySelector('input[name="regions[]"][data-filter-input]')
                    ?.dispatchEvent(new Event('change', { bubbles: true }));
                closeMega(section);
            }
            return;
        }

        // Selecting a category/sub link or a city option — the option itself does
        // the filtering (catalog.js); just close the dropdown (it fades out before
        // the debounced results swap).
        const option = event.target.closest('[data-catalog-category-link], .cat-filter__city');
        if (option && option.closest('[data-cat-mega], [data-cat-filter]')) {
            const section = sectionOf(option);
            if (section && section.classList.contains('is-open')) closeMega(section);
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

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAll();
    });
})();
