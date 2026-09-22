@push('scripts')
    <script>
        (() => {
            const body = document.body;
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

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
