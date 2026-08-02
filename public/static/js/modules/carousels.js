(() => {
    const viewports = document.querySelectorAll('[data-carousel]');
    const controls = document.querySelectorAll('[data-carousel-prev], [data-carousel-next]');
    if (!viewports.length && !controls.length) return;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const AUTOPLAY_DELAY = 3200;
    const cardSelector = '.result-card--carousel, .dovira-catalog-card, .home-intents__item';

    const autoplayTimers = new Map();
    const loopUnits = new Map();      // carouselName -> px width of one original set (0 = no loop)
    const scrollIdleTimers = new Map();

    const getViewport = (name) => document.querySelector(`[data-carousel="${name}"]`);
    const getAllCards = (vp) => Array.from(vp.querySelectorAll(cardSelector));
    const getOriginalCards = (vp) => getAllCards(vp).filter((c) => c.dataset.carouselClone !== '1');

    const getGap = (vp) => {
        const styles = window.getComputedStyle(vp);
        return parseFloat(styles.columnGap || styles.gap || '0') || 0;
    };

    const getStep = (vp) => {
        const card = getOriginalCards(vp)[0];
        if (!card) return vp.clientWidth * 0.9;
        return card.getBoundingClientRect().width + getGap(vp);
    };

    const getUnit = (name) => loopUnits.get(name) || 0;

    /* Duplicate the cards once so scrolling can wrap seamlessly. */
    const setupLoop = (vp, name) => {
        getAllCards(vp).filter((c) => c.dataset.carouselClone === '1').forEach((c) => c.remove());

        const originals = getOriginalCards(vp);
        const overflow = vp.scrollWidth - vp.clientWidth > 4;

        if (!originals.length || !overflow) {
            loopUnits.set(name, 0);
            return;
        }

        const fragment = document.createDocumentFragment();
        originals.forEach((card) => {
            const clone = card.cloneNode(true);
            clone.dataset.carouselClone = '1';
            clone.setAttribute('aria-hidden', 'true');
            clone.querySelectorAll('a, button, input, [tabindex]').forEach((el) => { el.tabIndex = -1; });
            if (clone.matches('a, button')) clone.tabIndex = -1;
            fragment.appendChild(clone);
        });
        vp.appendChild(fragment);

        const firstClone = vp.querySelector(`${cardSelector}[data-carousel-clone="1"]`);
        loopUnits.set(name, firstClone ? Math.round(firstClone.offsetLeft) : 0);
    };

    /* Keep scrollLeft within [0, unit) — invisible because clones match originals. */
    const normalizeLoop = (vp, name) => {
        const unit = getUnit(name);
        if (unit <= 0) return;
        if (vp.scrollLeft >= unit) {
            vp.scrollLeft -= unit;
        }
    };

    const getMetrics = (vp) => {
        const cards = getOriginalCards(vp);
        const step = getStep(vp);
        const visibleCards = cards.length ? Math.max(1, Math.round(vp.clientWidth / step)) : 1;
        const pageCount = cards.length ? Math.max(1, Math.ceil(cards.length / visibleCards)) : 0;
        const unit = getUnit(vp.dataset.carousel);
        const maxScrollLeft = Math.max(0, vp.scrollWidth - vp.clientWidth);
        const effectiveScroll = unit > 0 ? ((vp.scrollLeft % unit) + unit) % unit : vp.scrollLeft;
        const pageStep = step * visibleCards;
        const rawPageIndex = pageStep > 0 ? Math.round(effectiveScroll / pageStep) : 0;
        const pageIndex = pageCount ? ((rawPageIndex % pageCount) + pageCount) % pageCount : 0;

        return { cards, step, visibleCards, pageCount, pageIndex, unit, maxScrollLeft, pageStep };
    };

    const getDots = (name) => document.querySelectorAll(`[data-carousel-dot="${name}"]`);

    const ensurePagination = (name) => {
        const vp = getViewport(name);
        if (!vp) return null;
        let pagination = document.querySelector(`[data-carousel-pagination="${name}"]`);
        if (!pagination) {
            pagination = document.createElement('div');
            pagination.className = 'carousel-pagination';
            pagination.dataset.carouselPagination = name;
            pagination.setAttribute('aria-label', 'Пагінація каруселі');
            vp.insertAdjacentElement('afterend', pagination);
        }
        return pagination;
    };

    function syncPagination(name) {
        const vp = getViewport(name);
        const pagination = ensurePagination(name);
        if (!vp || !pagination) return;

        const { pageCount, maxScrollLeft } = getMetrics(vp);
        const shouldShow = pageCount > 1 && maxScrollLeft > 2;
        pagination.hidden = !shouldShow;

        if (!shouldShow) {
            pagination.replaceChildren();
            return;
        }
        if (Array.from(getDots(name)).length === pageCount) return;

        pagination.replaceChildren();
        for (let index = 0; index < pageCount; index += 1) {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.className = 'carousel-pagination__dot';
            dot.dataset.carouselDot = name;
            dot.dataset.index = String(index);
            dot.setAttribute('aria-label', `Перейти до слайду ${index + 1}`);
            pagination.append(dot);
        }
    }

    function updateActiveState(name) {
        const vp = getViewport(name);
        if (!vp) return;
        const { cards, pageIndex, visibleCards } = getMetrics(vp);
        if (!cards.length) return;

        const index = Math.max(0, Math.min(cards.length - 1, pageIndex * visibleCards));
        cards.forEach((card, cardIndex) => card.classList.toggle('is-active', cardIndex === index));

        getDots(name).forEach((dot) => {
            const isActive = Number(dot.dataset.index) === pageIndex;
            dot.classList.toggle('is-active', isActive);
            dot.setAttribute('aria-current', isActive ? 'true' : 'false');
        });
    }

    function updateControlsState(name) {
        const vp = getViewport(name);
        if (!vp) return;
        const { maxScrollLeft, unit } = getMetrics(vp);
        const hasOverflow = maxScrollLeft > 2;
        const looping = unit > 0;
        const atStart = vp.scrollLeft <= 2;
        const atEnd = vp.scrollLeft >= (maxScrollLeft - 2);

        document.querySelectorAll(`[data-carousel-prev="${name}"]`).forEach((button) => {
            button.disabled = !hasOverflow || (!looping && atStart);
            button.setAttribute('aria-disabled', String(button.disabled));
        });
        document.querySelectorAll(`[data-carousel-next="${name}"]`).forEach((button) => {
            button.disabled = !hasOverflow || (!looping && atEnd);
            button.setAttribute('aria-disabled', String(button.disabled));
        });
    }

    const scrollCarousel = (name, direction) => {
        const vp = getViewport(name);
        if (!vp) return;
        const unit = getUnit(name);
        const step = getStep(vp);

        // Wrap when stepping left past the start of a looping carousel.
        if (unit > 0 && direction < 0 && vp.scrollLeft <= 2) {
            vp.scrollLeft = unit;
        }

        vp.scrollBy({ left: step * direction, behavior: 'smooth' });
        // Looping carousels normalize on scroll-idle (same path as manual swipe).
        window.setTimeout(() => { updateActiveState(name); updateControlsState(name); }, 240);
    };

    const advanceCarousel = (name) => {
        const vp = getViewport(name);
        if (!vp) return;
        const { maxScrollLeft, step, unit } = getMetrics(vp);
        if (maxScrollLeft <= 2) return;

        // Advance one card at a time, like the manual controls.
        if (unit > 0) {
            vp.scrollBy({ left: step, behavior: 'smooth' });
            return;
        }

        // Non-looping fallback: rewind at the end.
        if (vp.scrollLeft >= (maxScrollLeft - 2)) {
            vp.scrollTo({ left: 0, behavior: 'smooth' });
        } else {
            vp.scrollBy({ left: step, behavior: 'smooth' });
        }
        window.setTimeout(() => { updateActiveState(name); updateControlsState(name); }, 260);
    };

    const stopAutoplay = (name) => {
        const timer = autoplayTimers.get(name);
        if (!timer) return;
        window.clearInterval(timer);
        autoplayTimers.delete(name);
    };

    const startAutoplay = (name) => {
        // Автопрокрутку вимкнено свідомо: карусель, що їде сама, забирає
        // контроль у користувача й смикає увагу. Керування — стрілки/свайп.
        return;
        if (prefersReducedMotion) return;
        const vp = getViewport(name);
        if (!vp || document.hidden) return;
        if (getMetrics(vp).maxScrollLeft <= 2) { stopAutoplay(name); return; }
        stopAutoplay(name);
        autoplayTimers.set(name, window.setInterval(() => advanceCarousel(name), AUTOPLAY_DELAY));
    };

    controls.forEach((control) => {
        control.addEventListener('click', () => {
            const name = control.dataset.carouselNext || control.dataset.carouselPrev;
            if (!name) return;
            const direction = control.dataset.carouselNext ? 1 : -1;
            stopAutoplay(name);
            scrollCarousel(name, direction);
            window.setTimeout(() => startAutoplay(name), AUTOPLAY_DELAY);
        });
    });

    document.addEventListener('click', (event) => {
        const dot = event.target.closest('[data-carousel-dot]');
        if (!dot) return;
        const name = dot.dataset.carouselDot;
        const vp = getViewport(name);
        if (!vp) return;
        const { pageStep } = getMetrics(vp);
        const index = Number(dot.dataset.index) || 0;
        stopAutoplay(name);
        vp.scrollTo({ left: pageStep * index, behavior: 'smooth' });
        window.setTimeout(() => { updateActiveState(name); startAutoplay(name); }, 240);
    });

    viewports.forEach((vp) => {
        const name = vp.dataset.carousel;
        if (!name) return;

        setupLoop(vp, name);
        syncPagination(name);
        updateActiveState(name);
        updateControlsState(name);
        startAutoplay(name);

        vp.addEventListener('scroll', () => {
            updateActiveState(name);
            updateControlsState(name);
            // Normalize once the user's scroll settles (seamless because clones match).
            if (getUnit(name) <= 0) return;
            window.clearTimeout(scrollIdleTimers.get(name));
            scrollIdleTimers.set(name, window.setTimeout(() => {
                normalizeLoop(vp, name);
            }, 140));
        }, { passive: true });

        vp.addEventListener('mouseenter', () => stopAutoplay(name), { passive: true });
        vp.addEventListener('mouseleave', () => startAutoplay(name), { passive: true });
        vp.addEventListener('focusin', () => stopAutoplay(name));
        vp.addEventListener('focusout', () => startAutoplay(name));
        vp.addEventListener('touchstart', () => stopAutoplay(name), { passive: true });
        vp.addEventListener('pointerdown', () => stopAutoplay(name), { passive: true });
    });

    let resizeTimer = 0;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            viewports.forEach((vp) => {
                const name = vp.dataset.carousel;
                if (!name) return;
                setupLoop(vp, name);
                syncPagination(name);
                updateControlsState(name);
                updateActiveState(name);
                startAutoplay(name);
            });
        }, 180);
    }, { passive: true });

    document.addEventListener('visibilitychange', () => {
        viewports.forEach((vp) => {
            const name = vp.dataset.carousel;
            if (!name) return;
            if (document.hidden) stopAutoplay(name);
            else startAutoplay(name);
        });
    });
})();
