/**
 * Позиція компанії щодо досьє: відправка на модерацію з кабінету.
 *
 * Свідомо без перезавантаження сторінки — кабінет на телефоні довгий, і
 * після сабміту людина опинялась би вгорі, не бачачи результату дії.
 */
(() => {
    const root = document.querySelector('[data-owner-statement]');
    if (!root) return;

    const input = root.querySelector('[data-owner-statement-input]');
    const submit = root.querySelector('[data-owner-statement-submit]');
    const counter = root.querySelector('[data-owner-statement-counter]');
    const error = root.querySelector('[data-owner-statement-error]');
    const pending = root.querySelector('[data-owner-statement-state="pending"]');
    const approved = root.querySelector('[data-owner-statement-state="approved"]');
    if (!input || !submit) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const MAX = 1500;

    const syncCounter = () => {
        if (counter) counter.textContent = `${input.value.length} / ${MAX}`;
    };

    const showError = (message) => {
        if (!error) return;
        error.textContent = message;
        error.hidden = ! message;
    };

    input.addEventListener('input', () => {
        syncCounter();
        showError('');
    });
    syncCounter();

    submit.addEventListener('click', async () => {
        const statement = input.value.trim();

        if (statement.length < 40) {
            showError('Напишіть хоча б кілька речень — від 40 символів.');
            input.focus();
            return;
        }

        submit.disabled = true;
        const label = submit.querySelector('span');
        const original = label?.textContent;
        if (label) label.textContent = 'Надсилаємо…';
        showError('');

        try {
            const response = await fetch(root.dataset.ownerStatementUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ statement }),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok || data.ok === false) {
                showError(data.error || data.message || 'Не вдалося надіслати. Спробуйте ще раз.');
                return;
            }

            // Нова редакція завжди йде на перевірку, тож публічний статус
            // знімаємо одразу — інакше в кабінеті висіло б «опубліковано».
            if (approved) approved.hidden = true;
            if (pending) pending.hidden = false;
            if (label) label.textContent = 'Оновити й надіслати';
            pending?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (e) {
            showError('Немає звʼязку з сервером. Спробуйте ще раз.');
        } finally {
            submit.disabled = false;
            if (label && label.textContent === 'Надсилаємо…') label.textContent = original || 'Надіслати на перевірку';
        }
    });
})();
