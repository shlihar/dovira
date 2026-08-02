/**
 * Форма заявки на профілі: AJAX-відправка ліда без перезавантаження,
 * потім показ подяки замість форми. Сторінка може містити кілька
 * екземплярів форми (desktop/mobile-варіанти блоку) — біндимо всі.
 */
(() => {
    const forms = Array.from(document.querySelectorAll('[data-lead-form]'));
    if (!forms.length) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    forms.forEach((form) => {
        const card = form.closest('[data-lead-card]');
        const successBox = card?.querySelector('[data-lead-success]');
        const successText = card?.querySelector('[data-lead-success-text]');
        const submitBtn = form.querySelector('button[type="submit"]');
        const label = form.querySelector('[data-lead-submit-label]');
        const formCsrf = csrf || form.querySelector('input[name="_token"]')?.value || '';

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (submitBtn.disabled) return;

            submitBtn.disabled = true;
            if (label) label.textContent = 'Надсилаємо…';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': formCsrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const payload = await response.json().catch(() => ({}));

                if (response.ok && payload.ok) {
                    if (successText && payload.message) successText.textContent = payload.message;
                    form.hidden = true;
                    if (successBox) successBox.hidden = false;
                    window.doviraTrack?.('cta_click', 'profile_lead_sent');
                    return;
                }

                const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                throw new Error(firstError || payload?.message || 'Не вдалося надіслати. Спробуйте ще раз.');
            } catch (error) {
                if (label) label.textContent = 'Надіслати заявку';
                submitBtn.disabled = false;
                let note = form.querySelector('[data-lead-error]');
                if (!note) {
                    note = document.createElement('p');
                    note.className = 'profile-lead-form__error';
                    note.setAttribute('data-lead-error', '');
                    form.appendChild(note);
                }
                note.textContent = error.message;
            }
        });
    });
})();
