/**
 * OTP-підтвердження прав на профіль (PRO-кабінет → крок «claim-details»).
 * Надсилає код на контакт, указаний у профілі (email/телефон), і перевіряє
 * його. Успіх → авто-апрув на сервері й редірект в огляд профілю. Якщо в
 * профілі немає контактів — показуємо ручний fallback (форма заявки).
 */
(() => {
    const otp = document.querySelector('[data-claim-otp]');
    if (!otp) return;

    const detailsStep = otp.closest('[data-claim-step="claim-details"]');
    const profileIdInput = document.querySelector('[data-claim-profile-id]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const channelsBox = otp.querySelector('[data-otp-channels]');
    const enterBox = otp.querySelector('[data-otp-enter]');
    const sentNote = otp.querySelector('[data-otp-sent]');
    const codeInput = otp.querySelector('[data-otp-code]');
    const verifyBtn = otp.querySelector('[data-otp-verify]');
    const resendBtn = otp.querySelector('[data-otp-resend]');
    const errorNote = otp.querySelector('[data-otp-error]');
    const manualBox = document.querySelector('[data-claim-manual]');

    const CHANNEL_META = {
        email: { label: 'Отримати код на email', sent: 'на email', icon: 'fa-regular fa-envelope' },
        phone: { label: 'Отримати SMS-код', sent: 'у SMS', icon: 'fa-solid fa-mobile-screen-button' },
    };

    let lastChannel = '';
    let loadedFor = '';
    let busy = false;

    const profileId = () => String(profileIdInput?.value || '').trim();

    const setError = (msg = '') => {
        if (!errorNote) return;
        errorNote.textContent = msg;
        errorNote.hidden = !msg;
    };

    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, data };
    };

    const showManual = () => {
        otp.hidden = true;
        if (manualBox) manualBox.hidden = false;
    };

    const renderChannels = (channels) => {
        channelsBox.innerHTML = '';
        channels.forEach((ch) => {
            const meta = CHANNEL_META[ch.channel] || { label: 'Отримати код', sent: '', icon: 'fa-solid fa-shield-halved' };
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn--primary claim-otp__channel';
            button.dataset.otpChannel = ch.channel;
            button.dataset.otpDestinationKey = ch.destination_key || '';
            button.innerHTML = `<i class="${meta.icon}" aria-hidden="true"></i><span>${meta.label} ${ch.masked}</span>`;
            button.addEventListener('click', () => sendCode(ch.channel, ch.destination_key || ''));
            channelsBox.appendChild(button);
        });
        channelsBox.hidden = false;
    };

    const loadChannels = async () => {
        const id = profileId();
        if (!id || loadedFor === id || busy) return;
        busy = true;
        setError('');
        // Скидаємо стан (раптом користувач повернувся й обрав інший профіль).
        otp.hidden = false;
        if (manualBox) manualBox.hidden = true;
        if (enterBox) enterBox.hidden = true;
        try {
            const { ok, data } = await post('/pro/account/claims/request-code', { profile_id: id });
            if (!ok) { setError(data.error || 'Помилка. Спробуйте пізніше.'); return; }
            loadedFor = id;
            if (data.manual || !Array.isArray(data.channels) || data.channels.length === 0) {
                showManual();
                return;
            }
            renderChannels(data.channels);
        } catch {
            setError('Помилка мережі. Спробуйте пізніше.');
        } finally {
            busy = false;
        }
    };

    const sendCode = async (channel, destinationKey = '') => {
        const id = profileId();
        if (!id || busy) return;
        busy = true;
        setError('');
        try {
            const payload = { profile_id: id, channel };
            if (destinationKey) payload.destination_key = destinationKey;
            const { ok, data } = await post('/pro/account/claims/request-code', payload);
            if (!ok || !data.ok) { setError(data.error || 'Не вдалося надіслати код.'); return; }
            lastChannel = channel;
            if (resendBtn) resendBtn.dataset.otpDestinationKey = destinationKey;
            channelsBox.hidden = true;
            enterBox.hidden = false;
            if (sentNote) sentNote.textContent = `Код надіслано ${CHANNEL_META[channel]?.sent || ''} ${data.masked}. Дійсний ${data.ttl} хв.`;
            codeInput?.focus();
        } catch {
            setError('Помилка мережі. Спробуйте пізніше.');
        } finally {
            busy = false;
        }
    };

    const verify = async () => {
        const id = profileId();
        const code = String(codeInput?.value || '').trim();
        if (!id) return;
        if (!/^\d{4,6}$/.test(code)) { setError('Введіть код із листа/SMS.'); return; }
        if (busy) return;
        busy = true;
        setError('');
        try {
            const { ok, data } = await post('/pro/account/claims/verify-code', { profile_id: id, code });
            if (ok && data.ok && data.redirect) { window.location.assign(data.redirect); return; }
            setError(data.error || 'Невірний код.');
        } catch {
            setError('Помилка мережі. Спробуйте пізніше.');
        } finally {
            busy = false;
        }
    };

    verifyBtn?.addEventListener('click', verify);
    codeInput?.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); verify(); } });
    resendBtn?.addEventListener('click', () => { if (lastChannel) sendCode(lastChannel, resendBtn.dataset.otpDestinationKey || ''); });

    // Ініціалізація: коли крок «claim-details» стає видимим — тягнемо канали.
    const isVisible = () => detailsStep && !detailsStep.hidden;
    if (isVisible()) loadChannels();
    if (detailsStep) {
        new MutationObserver(() => { if (isVisible()) loadChannels(); })
            .observe(detailsStep, { attributes: true, attributeFilter: ['hidden'] });
    }
})();
