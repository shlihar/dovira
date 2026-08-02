(() => {
    const roots = document.querySelectorAll('[data-header-search]');
    if (!roots.length) return;

    roots.forEach((root) => {
        const form = root.querySelector('[data-search-form]');
        const trigger = root.querySelector('[data-header-search-trigger]');
        const input = form?.querySelector('input[name="q"]');
        if (!form || !trigger || !input) return;

        let closeTimer = null;

        const clearCloseTimer = () => {
            if (!closeTimer) return;
            window.clearTimeout(closeTimer);
            closeTimer = null;
        };

        const shouldStayOpen = () => {
            const hasFocus = root.contains(document.activeElement);
            const hasOpenSuggest = form.classList.contains('is-open');

            return hasFocus || hasOpenSuggest;
        };

        const open = () => {
            clearCloseTimer();
            root.classList.add('is-open');
        };

        const closeDeferred = () => {
            clearCloseTimer();
            closeTimer = window.setTimeout(() => {
                if (shouldStayOpen()) return;
                root.classList.remove('is-open');
            }, 140);
        };

        trigger.addEventListener('click', (event) => {
            if (!root.classList.contains('is-open')) {
                event.preventDefault();
                open();
                input.focus();
                return;
            }

            if (!input.value.trim()) {
                event.preventDefault();
                input.focus();
            }
        });

        form.addEventListener('focusin', open);
        form.addEventListener('focusout', closeDeferred);
        form.addEventListener('submit', () => {
            root.classList.remove('is-open');
        });

        const observer = new MutationObserver(() => {
            if (form.classList.contains('is-open')) {
                open();
                return;
            }

            closeDeferred();
        });

        observer.observe(form, { attributes: true, attributeFilter: ['class'] });

        document.addEventListener('pointerdown', (event) => {
            if (root.contains(event.target)) return;
            root.classList.remove('is-open');
        });
    });
})();

(() => {
    const items = document.querySelectorAll('.faq .faq__item');
    if (!items.length) return;

    const getPanel = (item) => item.querySelector('.faq__panel');
    const getSummary = (item) => item.querySelector('.faq__question');

    // Плавний акордеон: анімуємо height від 0 до реальної (scrollHeight) і
    // назад, з перериванням на льоту. Модерна крива Material (.4,0,.2,1).
    // Після відкриття лишаємо height:auto, щоб контент був адаптивним.
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const clearEnd = (item, panel) => {
        if (item._faqEnd) {
            panel.removeEventListener('transitionend', item._faqEnd);
            item._faqEnd = null;
        }
    };

    const onEnd = (item, panel, cb) => {
        clearEnd(item, panel);
        item._faqEnd = (e) => {
            if (e.target !== panel || e.propertyName !== 'height') return;
            clearEnd(item, panel);
            cb();
        };
        panel.addEventListener('transitionend', item._faqEnd);
    };

    const open = (item, panel, summary) => {
        item.setAttribute('open', '');
        item.classList.add('is-open');
        summary.setAttribute('aria-expanded', 'true');
        if (reduceMotion) { panel.style.height = 'auto'; return; }
        panel.style.height = `${panel.getBoundingClientRect().height}px`;
        void panel.offsetHeight;
        panel.style.height = `${panel.scrollHeight}px`;
        onEnd(item, panel, () => {
            if (item.classList.contains('is-open')) panel.style.height = 'auto';
        });
    };

    const close = (item, panel, summary) => {
        item.classList.remove('is-open');
        summary.setAttribute('aria-expanded', 'false');
        if (reduceMotion) { panel.style.height = '0px'; item.removeAttribute('open'); return; }
        panel.style.height = `${panel.getBoundingClientRect().height}px`;
        void panel.offsetHeight;
        panel.style.height = '0px';
        onEnd(item, panel, () => {
            if (!item.classList.contains('is-open')) item.removeAttribute('open');
        });
    };

    items.forEach((item) => {
        const panel = getPanel(item);
        const summary = getSummary(item);
        if (!panel || !summary) return;

        if (item.hasAttribute('open')) {
            item.classList.add('is-open');
            panel.style.height = 'auto';
            summary.setAttribute('aria-expanded', 'true');
        } else {
            panel.style.height = '0px';
            summary.setAttribute('aria-expanded', 'false');
        }

        summary.addEventListener('click', (event) => {
            event.preventDefault();
            if (item.classList.contains('is-open')) close(item, panel, summary);
            else open(item, panel, summary);
        });
    });
})();

(() => {
    const accordions = document.querySelectorAll(
        '.reviews-filters-accordion > details, .reviews-filters-accordion .filter-categories > details'
    );
    if (!accordions.length) return;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const DURATION_MS = 240;
    const EASE = 'cubic-bezier(0.22, 1, 0.36, 1)';

    const getParts = (details) => {
        const summary = details.querySelector(':scope > summary');
        const panel = summary?.nextElementSibling;
        return { summary, panel };
    };

        const setInitialState = (details) => {
        const { panel } = getParts(details);
        if (!panel) return;

        panel.style.overflow = 'hidden';
        panel.style.transition = `height ${DURATION_MS}ms ${EASE}, opacity ${Math.max(170, DURATION_MS - 30)}ms ${EASE}, transform ${DURATION_MS}ms ${EASE}`;
        panel.style.willChange = 'auto';

        if (details.open) {
            panel.style.height = 'auto';
            panel.style.opacity = '1';
            panel.style.transform = 'translateY(0)';
            panel.setAttribute('aria-hidden', 'false');
        } else {
            panel.style.height = '0px';
            panel.style.opacity = '0';
            panel.style.transform = 'translateY(-4px)';
            panel.setAttribute('aria-hidden', 'true');
        }
    };

    const openAnimated = (details, panel) => {
        details.dataset.animating = '1';
        details.open = true;
        panel.style.willChange = 'height, opacity, transform';

        panel.style.height = '0px';
        panel.style.opacity = '0';
        panel.style.transform = 'translateY(-4px)';

        requestAnimationFrame(() => {
            const target = panel.scrollHeight;
            panel.style.height = `${target}px`;
            panel.style.opacity = '1';
            panel.style.transform = 'translateY(0)';
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            panel.style.height = 'auto';
            panel.style.willChange = 'auto';
            panel.setAttribute('aria-hidden', 'false');
            details.dataset.animating = '0';
        };
        panel.addEventListener('transitionend', onEnd);
    };

    const closeAnimated = (details, panel) => {
        details.dataset.animating = '1';
        panel.style.willChange = 'height, opacity, transform';
        const startHeight = panel.scrollHeight;
        panel.style.height = `${startHeight}px`;
        panel.style.opacity = '1';
        panel.style.transform = 'translateY(0)';

        requestAnimationFrame(() => {
            panel.style.height = '0px';
            panel.style.opacity = '0';
            panel.style.transform = 'translateY(-4px)';
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            details.open = false;
            panel.style.willChange = 'auto';
            panel.setAttribute('aria-hidden', 'true');
            details.dataset.animating = '0';
        };
        panel.addEventListener('transitionend', onEnd);
    };

    accordions.forEach((details) => {
        const { summary, panel } = getParts(details);
        if (!summary || !panel) return;

        setInitialState(details);

        summary.addEventListener('click', (event) => {
            if (event.target.closest('a[href]')) return;
            event.preventDefault();
            if (details.dataset.animating === '1') return;

            if (prefersReducedMotion) {
                details.open = !details.open;
                panel.style.height = details.open ? 'auto' : '0px';
                panel.style.opacity = details.open ? '1' : '0';
                return;
            }

            if (details.open) {
                closeAnimated(details, panel);
            } else {
                openAnimated(details, panel);
            }
        });
    });
})();

(() => {
    const toggles = document.querySelectorAll('[data-show-more-toggle]');
    if (!toggles.length) return;

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

    const animateListHeight = (list, hiddenItems, expand, scope) => {
        if (list.dataset.animating === '1') return;
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
            // Force reflow.
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
        // Force reflow.
        void list.offsetHeight;
        requestAnimationFrame(() => {
            hiddenItems.forEach((item) => {
                item.hidden = true;
            });
            list.style.height = `${end}px`;
            list.addEventListener('transitionend', onEnd);
            followRaf = window.requestAnimationFrame(followScroll);
        });
    };

    toggles.forEach((toggle) => {
        const key = toggle.getAttribute('data-show-more-toggle');
        const scope = toggle.closest('.minimal-filter-group');
        const list = scope?.querySelector(`[data-show-more-list="${key}"]`);
        if (!list) return;

        const hiddenItems = Array.from(list.querySelectorAll('[data-show-more-item]'));
        if (!hiddenItems.length) {
            toggle.hidden = true;
            return;
        }

        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            const next = !expanded;
            toggle.setAttribute('aria-expanded', next ? 'true' : 'false');
            toggle.innerHTML = `${next ? 'Згорнути' : 'Показати всі'} <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>`;
            animateListHeight(list, hiddenItems, next, scope);
        });
    });
})();
