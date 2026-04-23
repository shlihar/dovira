(() => {
    const burger = document.querySelector('[data-burger]');
    const mobile = document.querySelector('[data-mobile]');
    const overlay = document.querySelector('[data-overlay]');
    if (!burger || !mobile || !overlay) return;
    const closeBtn = mobile.querySelector('[data-close]');

    const ANIM_MS = 220;
    let closeTimer = null;
    let lockedScrollY = 0;
    let isScrollLocked = false;

    const lockPageScroll = () => {
        if (isScrollLocked) return;
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${lockedScrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        isScrollLocked = true;
    };

    const unlockPageScroll = () => {
        if (!isScrollLocked) return;
        const top = document.body.style.top;
        const restoreY = top ? Math.abs(parseInt(top, 10)) : lockedScrollY;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        isScrollLocked = false;
        const prevScrollBehavior = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, Number.isFinite(restoreY) ? restoreY : 0);
        document.documentElement.style.scrollBehavior = prevScrollBehavior;
    };

    const setOpen = (open) => {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        burger.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            mobile.hidden = false;
            overlay.hidden = false;
            lockPageScroll();
            // Allow layout to apply before starting transition.
            requestAnimationFrame(() => {
                document.documentElement.classList.add('is-menu-open');
            });
            return;
        }

        document.documentElement.classList.remove('is-menu-open');
        unlockPageScroll();
        closeTimer = window.setTimeout(() => {
            mobile.hidden = true;
            overlay.hidden = true;
        }, ANIM_MS);
    };

    burger.addEventListener('click', () => {
        const open = burger.getAttribute('aria-expanded') !== 'true';
        setOpen(open);
    });

    mobile.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (link) setOpen(false);
    });

    overlay.addEventListener('click', () => setOpen(false));
    closeBtn?.addEventListener('click', () => setOpen(false));

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });
})();

(() => {
    const avatars = document.querySelectorAll('.avatar[data-seed]');
    const logoPlaceholders = document.querySelectorAll('.review-list-card__logo[data-seed]');
    const sideAvatars = document.querySelectorAll('.profile-side-list__avatar[data-seed]');
    if (!avatars.length && !logoPlaceholders.length && !sideAvatars.length) return;

    const hash = (input) => {
        let value = 0;
        for (let i = 0; i < input.length; i += 1) {
            value = (value << 5) - value + input.charCodeAt(i);
            value |= 0;
        }
        return Math.abs(value);
    };

    const palettes = [
        { bg1: '#eaf2ff', bg2: '#1f68ff', ring: 'rgba(31, 104, 255, 0.22)', glow: 'rgba(31, 104, 255, 0.26)' },
        { bg1: '#e8f5ff', bg2: '#0f4fb8', ring: 'rgba(15, 79, 184, 0.22)', glow: 'rgba(15, 79, 184, 0.26)' },
        { bg1: '#edf0ff', bg2: '#3b5bff', ring: 'rgba(59, 91, 255, 0.20)', glow: 'rgba(59, 91, 255, 0.24)' },
        { bg1: '#eefcff', bg2: '#1483ff', ring: 'rgba(20, 131, 255, 0.18)', glow: 'rgba(20, 131, 255, 0.22)' },
        { bg1: '#e8fffb', bg2: '#0d86c6', ring: 'rgba(13, 134, 198, 0.18)', glow: 'rgba(13, 134, 198, 0.22)' },
        { bg1: '#f1f0ff', bg2: '#5a4bff', ring: 'rgba(90, 75, 255, 0.18)', glow: 'rgba(90, 75, 255, 0.22)' },
        { bg1: '#eaf6ff', bg2: '#0b63d6', ring: 'rgba(11, 99, 214, 0.20)', glow: 'rgba(11, 99, 214, 0.24)' },
        { bg1: '#eff7ff', bg2: '#1b86ff', ring: 'rgba(27, 134, 255, 0.18)', glow: 'rgba(27, 134, 255, 0.22)' },
        { bg1: '#e9f3ff', bg2: '#2753d9', ring: 'rgba(39, 83, 217, 0.20)', glow: 'rgba(39, 83, 217, 0.24)' },
        { bg1: '#e8f0ff', bg2: '#3a66ff', ring: 'rgba(58, 102, 255, 0.18)', glow: 'rgba(58, 102, 255, 0.22)' },
    ];

    avatars.forEach((avatar) => {
        const seed = avatar.dataset.seed ?? '';
        avatar.classList.add('avatar--placeholder');
        const value = hash(seed);
        const palette = palettes[value % palettes.length];
        const invert = ((value >> 8) & 1) === 1;

        avatar.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
        avatar.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
        avatar.style.setProperty('--av-icon', '#ffffff');
        avatar.style.setProperty('--av-ring', palette.ring);
        avatar.style.setProperty('--av-glow', palette.glow);
    });

    logoPlaceholders.forEach((logo) => {
        const seed = logo.dataset.seed ?? '';
        const value = hash(seed);
        const palette = palettes[value % palettes.length];
        const invert = ((value >> 8) & 1) === 1;

        logo.classList.add('has-random-gradient');
        logo.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
        logo.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
        logo.style.setProperty('--av-text', '#ffffff');
    });

    sideAvatars.forEach((avatar) => {
        const seed = avatar.dataset.seed ?? '';
        const value = hash(seed);
        const palette = palettes[value % palettes.length];
        const invert = ((value >> 8) & 1) === 1;

        avatar.style.backgroundImage = `linear-gradient(135deg, ${invert ? palette.bg2 : palette.bg1} 0%, ${invert ? palette.bg1 : palette.bg2} 100%)`;
    });
})();

(() => {
    const controls = document.querySelectorAll('[data-carousel-prev], [data-carousel-next]');
    const dots = document.querySelectorAll('[data-carousel-dot]');
    if (!controls.length && !dots.length) return;

    const getStep = (viewport) => {
        const card = viewport.querySelector('.result-card--carousel');
        if (!card) return viewport.clientWidth * 0.9;
        const track = viewport.querySelector('.reviews-carousel__track');
        const trackStyles = track ? window.getComputedStyle(track) : null;
        const gap = trackStyles ? parseFloat(trackStyles.columnGap || trackStyles.gap || '0') : 0;
        return card.getBoundingClientRect().width + gap;
    };

    const updateActiveState = (carouselName) => {
        const viewport = document.querySelector(`[data-carousel="${carouselName}"]`);
        if (!viewport) return;

        const cards = viewport.querySelectorAll('.result-card--carousel');
        if (!cards.length) return;

        const step = getStep(viewport);
        const index = Math.max(0, Math.min(cards.length - 1, Math.round(viewport.scrollLeft / step)));

        cards.forEach((card, cardIndex) => {
            card.classList.toggle('is-active', cardIndex === index);
        });

        document.querySelectorAll(`[data-carousel-dot="${carouselName}"]`).forEach((dot) => {
            dot.classList.toggle('is-active', Number(dot.dataset.index) === index);
        });
    };

    const scrollCarousel = (carouselName, direction) => {
        const viewport = document.querySelector(`[data-carousel="${carouselName}"]`);
        if (!viewport) return;
        viewport.scrollBy({
            left: getStep(viewport) * direction,
            behavior: 'smooth',
        });
        window.setTimeout(() => updateActiveState(carouselName), 220);
    };

    controls.forEach((control) => {
        control.addEventListener('click', () => {
            if (control.dataset.carouselNext) {
                scrollCarousel(control.dataset.carouselNext, 1);
                return;
            }

            if (control.dataset.carouselPrev) {
                scrollCarousel(control.dataset.carouselPrev, -1);
            }
        });
    });

    dots.forEach((dot) => {
        dot.addEventListener('click', () => {
            const carouselName = dot.dataset.carouselDot;
            const viewport = document.querySelector(`[data-carousel="${carouselName}"]`);
            if (!viewport) return;
            const index = Number(dot.dataset.index) || 0;
            viewport.scrollTo({
                left: getStep(viewport) * index,
                behavior: 'smooth',
            });
            window.setTimeout(() => updateActiveState(carouselName), 220);
        });
    });

    document.querySelectorAll('[data-carousel]').forEach((viewport) => {
        const carouselName = viewport.dataset.carousel;
        if (!carouselName) return;
        viewport.addEventListener('scroll', () => updateActiveState(carouselName), { passive: true });
        updateActiveState(carouselName);
    });
})();

(() => {
    if (!document.body.classList.contains('page-pro')) return;
    const header = document.querySelector('[data-header]');
    if (!header) return;

    const syncHeaderState = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 24);
    };

    syncHeaderState();
    window.addEventListener('scroll', syncHeaderState, { passive: true });
})();

(() => {
    if (!document.body.classList.contains('page-pro')) return;

    const toggles = document.querySelectorAll('[data-billing-toggle]');
    const amounts = document.querySelectorAll('[data-price-amount]');
    const periods = document.querySelectorAll('[data-price-period]');
    const switcher = document.querySelector('.pro-pricing__switch');
    if (!toggles.length || !amounts.length || !periods.length) return;

    const formatPrice = (value) => Number(value || 0).toLocaleString('uk-UA');

    const setBilling = (mode, animate = true) => {
        toggles.forEach((toggle) => {
            const isActive = toggle.dataset.billingToggle === mode;
            toggle.classList.toggle('is-active', isActive);
            toggle.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        if (switcher) {
            switcher.classList.toggle('is-yearly', mode === 'yearly');
        }

        const priceRows = document.querySelectorAll('.pro-price-card__price');
        if (animate) {
            priceRows.forEach((row) => row.classList.add('is-updating'));
        }

        const commit = () => {
            amounts.forEach((amount) => {
                const value = mode === 'yearly' ? amount.dataset.yearly : amount.dataset.monthly;
                amount.textContent = formatPrice(value);
            });

            periods.forEach((period) => {
                const value = mode === 'yearly' ? period.dataset.yearly : period.dataset.monthly;
                period.textContent = value || '';
            });

            if (animate) {
                requestAnimationFrame(() => {
                    priceRows.forEach((row) => row.classList.remove('is-updating'));
                });
            }
        };

        if (!animate) {
            commit();
            return;
        }

        window.setTimeout(commit, 130);
    };

    toggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            setBilling(toggle.dataset.billingToggle || 'monthly', true);
        });
    });

    setBilling('monthly', false);
})();

(() => {
    const roots = document.querySelectorAll('.page-home-main [data-intents-root]');
    if (!roots.length) return;

    roots.forEach((root) => {
        const track = root.querySelector('[data-intents-track]');
        const prev = root.querySelector('[data-intents-prev]');
        const next = root.querySelector('[data-intents-next]');
        if (!track || !prev || !next) return;

        const getStep = () => {
            const firstItem = track.querySelector('.home-intents__item');
            if (!firstItem) return 260;
            const style = window.getComputedStyle(track);
            const gap = parseFloat(style.columnGap || style.gap || '0');
            return firstItem.getBoundingClientRect().width + gap;
        };

        const setDisabledState = (button, disabled) => {
            button.disabled = disabled;
            button.style.opacity = disabled ? '0.45' : '1';
        };

        const updateButtons = () => {
            const canScrollPrev = track.scrollLeft > 2;
            const canScrollNext = Math.ceil(track.scrollLeft + track.clientWidth) < Math.floor(track.scrollWidth - 1);

            setDisabledState(prev, !canScrollPrev);
            setDisabledState(next, !canScrollNext);
        };

        prev.addEventListener('click', () => {
            track.scrollBy({ left: -getStep(), behavior: 'smooth' });
        });

        next.addEventListener('click', () => {
            track.scrollBy({ left: getStep(), behavior: 'smooth' });
        });

        track.addEventListener('scroll', updateButtons, { passive: true });
        window.addEventListener('resize', updateButtons, { passive: true });
        window.addEventListener('load', updateButtons, { passive: true });

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(updateButtons);
            observer.observe(track);
        }

        requestAnimationFrame(() => {
            updateButtons();
            requestAnimationFrame(updateButtons);
        });
    });
})();

(() => {
    const searchForms = document.querySelectorAll('[data-search-form]');
    if (!searchForms.length) return;

    searchForms.forEach((form) => {
        const input = form.querySelector('input[name="q"]');
        const suggest = form.querySelector('[data-search-suggest]');
        if (!input || !suggest) return;
        const isMobileSearch = form.classList.contains('hero__mobile-search');
        const isSmallViewport = window.matchMedia('(max-width: 760px)').matches;
        const usePortalSuggest = !isMobileSearch && !isSmallViewport;
        let rafId = 0;

        if (usePortalSuggest && !suggest.dataset.portalMounted) {
            suggest.dataset.portalMounted = '1';
            document.body.appendChild(suggest);
        }

        const placeSuggest = () => {
            if (suggest.hidden || !usePortalSuggest) return;
            const rect = form.getBoundingClientRect();
            suggest.style.left = `${Math.round(window.scrollX + rect.left)}px`;
            suggest.style.top = `${Math.round(window.scrollY + rect.bottom + 10)}px`;
            suggest.style.width = `${Math.round(rect.width)}px`;
        };

        const schedulePlaceSuggest = () => {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(() => {
                rafId = 0;
                placeSuggest();
            });
        };

        const open = () => {
            suggest.hidden = false;
            form.classList.add('is-open');
            if (usePortalSuggest) {
                schedulePlaceSuggest();
            }
        };

        const close = () => {
            suggest.hidden = true;
            form.classList.remove('is-open');
            suggest.style.removeProperty('left');
            suggest.style.removeProperty('top');
            suggest.style.removeProperty('width');
        };

        form.addEventListener('focusin', open);
        form.addEventListener('pointerdown', open);
        suggest.addEventListener('pointerdown', open);

        form.addEventListener('submit', close);
        if (usePortalSuggest) {
            window.addEventListener('resize', schedulePlaceSuggest, { passive: true });
            window.addEventListener('scroll', schedulePlaceSuggest, { passive: true });
        }

        document.addEventListener('pointerdown', (event) => {
            if (!form.contains(event.target) && !suggest.contains(event.target)) {
                close();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
                input.blur();
            }
        });

        document.addEventListener('focusin', (event) => {
            if (!form.contains(event.target) && !suggest.contains(event.target)) {
                close();
            }
        });

        close();
    });
})();

(() => {
    const items = document.querySelectorAll('.faq .faq__item');
    if (!items.length) return;

    const getPanel = (item) => item.querySelector('.faq__panel');
    const getSummary = (item) => item.querySelector('.faq__question');
    const isAnimating = (item) => item.dataset.animating === '1';
    const setAnimating = (item, value) => {
        item.dataset.animating = value ? '1' : '0';
    };

    const animateOpen = (item) => {
        const panel = getPanel(item);
        if (!panel || isAnimating(item)) return;

        setAnimating(item, true);
        item.setAttribute('open', '');
        item.classList.add('is-open');

        panel.style.height = '0px';
        const targetHeight = panel.scrollHeight;

        requestAnimationFrame(() => {
            panel.style.height = `${targetHeight}px`;
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            if (item.classList.contains('is-open')) {
                panel.style.height = 'auto';
            }
            setAnimating(item, false);
        };

        panel.addEventListener('transitionend', onEnd);
    };

    const animateClose = (item) => {
        const panel = getPanel(item);
        if (!panel || isAnimating(item)) return;

        setAnimating(item, true);
        const startHeight = panel.getBoundingClientRect().height || panel.scrollHeight;
        panel.style.height = `${startHeight}px`;
        item.classList.remove('is-open');

        requestAnimationFrame(() => {
            panel.style.height = '0px';
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            item.removeAttribute('open');
            panel.style.height = '0px';
            setAnimating(item, false);
        };

        panel.addEventListener('transitionend', onEnd);
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
        setAnimating(item, false);

        summary.addEventListener('click', (event) => {
            event.preventDefault();
            if (item.classList.contains('is-open')) {
                animateClose(item);
                summary.setAttribute('aria-expanded', 'false');
                return;
            }
            animateOpen(item);
            summary.setAttribute('aria-expanded', 'true');
        });
    });
})();

(() => {
    const accordions = document.querySelectorAll('.reviews-filters-accordion details');
    if (!accordions.length) return;

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const DURATION_MS = 240;

    const getParts = (details) => {
        const summary = details.querySelector(':scope > summary');
        const panel = summary?.nextElementSibling;
        return { summary, panel };
    };

    const setInitialState = (details) => {
        const { panel } = getParts(details);
        if (!panel) return;

        panel.style.overflow = 'hidden';
        panel.style.transition = `height ${DURATION_MS}ms ease, opacity ${Math.max(160, DURATION_MS - 40)}ms ease`;
        panel.style.willChange = 'height, opacity';

        if (details.open) {
            panel.style.height = 'auto';
            panel.style.opacity = '1';
        } else {
            panel.style.height = '0px';
            panel.style.opacity = '0';
        }
    };

    const openAnimated = (details, panel) => {
        details.dataset.animating = '1';
        details.open = true;

        panel.style.height = '0px';
        panel.style.opacity = '0';

        requestAnimationFrame(() => {
            const target = panel.scrollHeight;
            panel.style.height = `${target}px`;
            panel.style.opacity = '1';
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            panel.style.height = 'auto';
            details.dataset.animating = '0';
        };
        panel.addEventListener('transitionend', onEnd);
    };

    const closeAnimated = (details, panel) => {
        details.dataset.animating = '1';
        const startHeight = panel.scrollHeight;
        panel.style.height = `${startHeight}px`;
        panel.style.opacity = '1';

        requestAnimationFrame(() => {
            panel.style.height = '0px';
            panel.style.opacity = '0';
        });

        const onEnd = (event) => {
            if (event.target !== panel || event.propertyName !== 'height') return;
            panel.removeEventListener('transitionend', onEnd);
            details.open = false;
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
    const root = document.querySelector('[data-faq-categories]');
    if (!root) return;

    const content = root.querySelector('.faq-hub__content');
    const tabs = Array.from(root.querySelectorAll('[data-faq-category]'));
    const panels = Array.from(root.querySelectorAll('[data-faq-category-panel]'));
    if (!tabs.length || !panels.length || !content) return;

    const activate = (key) => {
        const nextPanel = panels.find((panel) => panel.dataset.faqCategoryPanel === key);
        const currentPanel = panels.find((panel) => panel.classList.contains('is-active'));
        if (!nextPanel || currentPanel === nextPanel) return;

        tabs.forEach((tab) => {
            const active = tab.dataset.faqCategory === key;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active && window.matchMedia('(max-width: 760px)').matches) {
                tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
            }
        });

        content.classList.add('is-switching');
        window.setTimeout(() => {
            panels.forEach((panel) => {
                const active = panel === nextPanel;
                panel.hidden = !active;
                panel.classList.toggle('is-active', active);
            });
            content.classList.remove('is-switching');
        }, 120);
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => activate(tab.dataset.faqCategory || ''));
    });

    const initial = tabs.find((tab) => tab.classList.contains('is-active'))?.dataset.faqCategory || tabs[0].dataset.faqCategory || '';
    tabs.forEach((tab) => {
        const active = tab.dataset.faqCategory === initial;
        tab.classList.toggle('is-active', active);
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    panels.forEach((panel) => {
        const active = panel.dataset.faqCategoryPanel === initial;
        panel.classList.toggle('is-active', active);
        panel.hidden = !active;
    });
})();

(() => {
    const isDesktop = window.matchMedia('(min-width: 901px)').matches;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!isDesktop || reducedMotion) return;

    const targets = Array.from(document.querySelectorAll('main .section, main .hero'));
    if (!targets.length || !('IntersectionObserver' in window)) return;

    targets.forEach((target, index) => {
        target.classList.add('reveal-on-scroll');
        target.style.setProperty('--reveal-delay', `${Math.min(index * 45, 220)}ms`);
    });

    const observer = new IntersectionObserver((entries, obs) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-revealed');
            obs.unobserve(entry.target);
        });
    }, {
        threshold: 0.15,
        rootMargin: '0px 0px -8% 0px',
    });

    targets.forEach((target) => {
        const rect = target.getBoundingClientRect();
        const alreadyVisible = rect.top < window.innerHeight * 0.82;
        if (alreadyVisible) {
            target.classList.add('is-revealed');
            return;
        }
        observer.observe(target);
    });
})();
