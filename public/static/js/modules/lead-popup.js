/**
 * Попап форми заявки на профілі: відкривається кнопками
 * [data-open-lead-popup] (їх на сторінці кілька — сайдбар, мобільна картка),
 * закривається бекдропом, хрестиком або Esc.
 */
(() => {
    const popup = document.querySelector('[data-lead-popup]');
    if (!popup) return;

    const open = () => {
        popup.hidden = false;
        document.body.classList.add('is-lead-popup-open');
        requestAnimationFrame(() => {
            popup.classList.add('is-visible');
            popup.querySelector('input[name="name"]')?.focus({ preventScroll: true });
        });
    };

    const close = () => {
        popup.classList.remove('is-visible');
        document.body.classList.remove('is-lead-popup-open');
        window.setTimeout(() => { popup.hidden = true; }, 180);
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-open-lead-popup]');
        if (trigger) {
            event.preventDefault();
            open();
            return;
        }
        if (event.target.closest('[data-lead-popup-close]')) {
            event.preventDefault();
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !popup.hidden) close();
    });
})();
