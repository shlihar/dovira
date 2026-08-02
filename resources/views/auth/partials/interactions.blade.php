@push('scripts')
    <script>
        (() => {
            const body = document.body;
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const telegramToggle = document.querySelector('[data-auth-telegram-toggle]');
            const telegramPanel = document.querySelector('[data-auth-telegram-panel]');

            telegramToggle?.addEventListener('click', () => {
                if (!telegramPanel) {
                    return;
                }

                const nextState = telegramPanel.hidden;
                telegramPanel.hidden = false;
                window.requestAnimationFrame(() => {
                    telegramPanel.classList.toggle('is-open', nextState);
                    telegramToggle.setAttribute('aria-expanded', nextState ? 'true' : 'false');
                });

                if (!nextState) {
                    window.setTimeout(() => {
                        telegramPanel.hidden = true;
                    }, reduceMotion ? 0 : 220);
                }
            });

            document.querySelectorAll('.auth-form').forEach((form) => {
                form.addEventListener('submit', () => {
                    const submit = form.querySelector('.auth-submit');
                    submit?.classList.add('is-loading');
                    submit?.setAttribute('aria-busy', 'true');
                });
            });

            document.querySelectorAll('[data-auth-transition-link]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    if (reduceMotion || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                        return;
                    }

                    const href = link.getAttribute('href');
                    if (!href || href.startsWith('#')) {
                        return;
                    }

                    event.preventDefault();
                    body.classList.add('is-auth-leaving');
                    window.setTimeout(() => {
                        window.location.href = href;
                    }, 140);
                });
            });

            window.addEventListener('pageshow', () => {
                body.classList.remove('is-auth-leaving');
                document.querySelectorAll('.auth-submit.is-loading').forEach((button) => {
                    button.classList.remove('is-loading');
                    button.removeAttribute('aria-busy');
                });
            });
        })();
    </script>
@endpush
