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
    // Ефект появи секцій вимкнено: контент має бути на місці одразу,
    // без «підпливання» при скролі.
    return;
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

