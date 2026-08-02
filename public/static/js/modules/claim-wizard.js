/**
 * Inline "add a profile" wizard (PRO account → Привʼязка профілів).
 *
 * Two scenarios — link an existing catalogue profile, or create a new one —
 * each split into steps, like the review popup. The profile search is fully
 * client-side (no page reload): it hits the same /search/suggest endpoint and
 * writes the picked profile id into the claim form. Submitting is a normal
 * POST to the existing endpoints, so server-side validation still applies.
 */
(() => {
    const wizard = document.querySelector('[data-claim-wizard]');
    if (!wizard) return;

    const titleNode = wizard.querySelector('[data-claim-title]');
    const leadNode = wizard.querySelector('[data-claim-lead]');
    const progress = wizard.querySelector('[data-claim-progress]');
    const progressDots = Array.from(wizard.querySelectorAll('[data-claim-progress-dot]'));
    const backButton = wizard.querySelector('[data-claim-back]');
    const scenarioStep = wizard.querySelector('[data-claim-step="scenario"]');
    const flowForms = {
        claim: wizard.querySelector('[data-claim-flow="claim"]'),
        create: wizard.querySelector('[data-claim-flow="create"]'),
    };

    // Claim-flow specifics
    const claimForm = flowForms.claim;
    const profileIdInput = claimForm?.querySelector('[data-claim-profile-id]');
    const searchInput = claimForm?.querySelector('[data-claim-search-input]');
    const suggestBox = claimForm?.querySelector('[data-claim-suggest]');
    const selectedCard = claimForm?.querySelector('[data-claim-selected]');
    const selectedName = claimForm?.querySelector('[data-claim-selected-name]');
    const selectedMeta = claimForm?.querySelector('[data-claim-selected-meta]');
    const clearButton = claimForm?.querySelector('[data-claim-clear]');
    const roleInput = claimForm?.querySelector('[data-claim-role]');
    const nameInput = flowForms.create?.querySelector('[data-claim-name]');
    const supportBox = claimForm?.querySelector('[data-claim-support]');
    const supportLink = claimForm?.querySelector('[data-claim-support-link]');
    const supportStatus = claimForm?.querySelector('[data-claim-support-status]');

    const FLOWS = {
        claim: ['claim-search', 'claim-details'],
        create: ['create-basics', 'create-contacts'],
    };

    const META = {
        scenario: { title: 'Додати профіль у кабінет', lead: 'Оберіть сценарій: подати заявку на існуючий профіль або створити новий.' },
        'claim-search': { title: 'Прив’язати існуючий профіль', lead: 'Знайдіть профіль у каталозі й оберіть його зі списку.' },
        'claim-details': { title: 'Підтвердження прав', lead: 'Надішлемо код на контакт, указаний у профілі.' },
        'create-basics': { title: 'Створити новий профіль', lead: 'Основне про профіль — решту додасте згодом у редакторі.' },
        'create-contacts': { title: 'Створити новий профіль', lead: 'Контакти й опис — необов’язково, але з ними профіль повніший.' },
    };

    const SUGGEST_ENDPOINT = '/search/suggest?scope=claim_profiles&limit=8&q=';
    const DEBOUNCE_MS = 180;
    const MOBILE_SEARCH_BREAKPOINT = 760;
    const DEFAULT_SUPPORT_USERNAME = 'dovira_support';

    let currentFlow = '';
    let currentStep = 'scenario';
    let debounceId = 0;
    let activeRequest = 0;
    let mobileDebounceId = 0;
    let mobileSearchModal = null;
    let mobileSearchOpen = false;

    const isMobileSearchViewport = () => window.matchMedia(`(max-width: ${MOBILE_SEARCH_BREAKPOINT}px)`).matches;

    /* ---------------------------------------------------------------- */
    /* Steps & navigation                                               */
    /* ---------------------------------------------------------------- */

    const stepsOf = (flow) => FLOWS[flow] || [];

    const setError = (message = '') => {
        // Show the error slot inside the current step (there is one per step).
        const active = wizard.querySelector(`[data-claim-step="${currentStep}"]`);
        const node = active?.querySelector('[data-claim-error]');
        wizard.querySelectorAll('[data-claim-error]').forEach((n) => { n.textContent = ''; n.hidden = true; });
        if (message && node) {
            node.textContent = message;
            node.hidden = false;
        }
    };

    const renderChrome = () => {
        const meta = META[currentStep] || META.scenario;
        if (titleNode) titleNode.textContent = meta.title;
        if (leadNode) leadNode.textContent = meta.lead;

        const inFlow = currentFlow !== '' && currentStep !== 'scenario';
        if (backButton) backButton.hidden = currentStep === 'scenario';
        if (progress) progress.hidden = !inFlow;

        const idx = stepsOf(currentFlow).indexOf(currentStep);
        progressDots.forEach((dot) => {
            const n = Number(dot.dataset.claimProgressDot);
            dot.classList.toggle('is-active', n === idx + 1);
            dot.classList.toggle('is-done', n < idx + 1);
        });

        // Footer buttons live inside the active flow form.
        const form = flowForms[currentFlow];
        if (form) {
            const next = form.querySelector('[data-claim-next]');
            const submit = form.querySelector('[data-claim-submit]');
            const onLast = idx === stepsOf(currentFlow).length - 1;
            if (next) next.hidden = onLast;
            if (submit) submit.hidden = !onLast;
        }
    };

    const showStep = (step) => {
        currentStep = step;
        wizard.querySelectorAll('[data-claim-step]').forEach((section) => {
            section.hidden = section.dataset.claimStep !== step;
        });
        setError('');
        renderChrome();
    };

    const goToScenario = () => {
        currentFlow = '';
        currentStep = 'scenario';
        if (scenarioStep) scenarioStep.hidden = false;
        Object.values(flowForms).forEach((form) => { if (form) form.hidden = true; });
        if (backButton) backButton.hidden = true;
        if (progress) progress.hidden = true;
        setError('');
        renderChrome();
    };

    const enterFlow = (flow, step) => {
        if (!FLOWS[flow]) return;
        currentFlow = flow;
        if (scenarioStep) scenarioStep.hidden = true;
        Object.entries(flowForms).forEach(([name, form]) => {
            if (form) form.hidden = name !== flow;
        });
        showStep(step || stepsOf(flow)[0]);
    };

    /* ---------------------------------------------------------------- */
    /* Per-step validation                                              */
    /* ---------------------------------------------------------------- */

    const validateStep = (step) => {
        if (step === 'claim-search') {
            if (!profileIdInput?.value) {
                setError('Оберіть профіль зі списку.');
                searchInput?.focus();
                return false;
            }
        }
        // claim-details більше не має обов'язкових полів: підтвердження йде
        // через OTP (код на контакт із профілю) або ручну заявку у fallback.
        if (step === 'create-basics') {
            if (!String(nameInput?.value || '').trim()) {
                setError('Вкажіть назву профілю.');
                nameInput?.focus();
                return false;
            }
        }
        return true;
    };

    const goNext = () => {
        if (!validateStep(currentStep)) return;
        const steps = stepsOf(currentFlow);
        const idx = steps.indexOf(currentStep);
        if (idx < steps.length - 1) showStep(steps[idx + 1]);
    };

    const goBack = () => {
        const steps = stepsOf(currentFlow);
        const idx = steps.indexOf(currentStep);
        if (idx <= 0) { goToScenario(); return; }
        showStep(steps[idx - 1]);
    };

    /* ---------------------------------------------------------------- */
    /* Profile search (claim flow)                                      */
    /* ---------------------------------------------------------------- */

    const hideSuggest = () => { if (suggestBox) { suggestBox.hidden = true; suggestBox.innerHTML = ''; } };

    const supportBaseUrl = () => {
        const username = (wizard.dataset.claimSupportUsername || DEFAULT_SUPPORT_USERNAME).replace(/^@+/, '');
        return supportBox?.dataset.supportBaseUrl || `https://t.me/${username}`;
    };

    const profileUrlFromSlug = (slug) => {
        const normalized = String(slug || '').trim().replace(/^\/+|\/+$/g, '');
        if (!normalized) return '';
        return new URL(`/profiles/${encodeURIComponent(normalized)}`, window.location.origin).toString();
    };

    const buildSupportMessage = (id, name, slug = '') => {
        const cleanName = String(name || '').trim();
        if (!id || !cleanName) return '';

        const parts = [
            `Вітаю! Хочу підтвердити права на профіль "${cleanName}" на DOVIRA.`,
            `ID профілю: ${id}.`,
        ];
        const profileUrl = profileUrlFromSlug(slug);
        if (profileUrl) parts.push(`Посилання: ${profileUrl}.`);
        if (wizard.dataset.claimUserEmail) parts.push(`Мій акаунт: ${wizard.dataset.claimUserEmail}.`);

        return parts.join(' ');
    };

    const syncSupportLink = (id, name, slug = '') => {
        if (!supportLink) return;
        const message = buildSupportMessage(id, name, slug) || supportLink.dataset.supportMessage || '';
        const href = message
            ? `${supportBaseUrl()}?text=${encodeURIComponent(message)}`
            : supportBaseUrl();
        supportLink.href = href;
        supportLink.dataset.supportMessage = message;
        if (supportStatus) {
            supportStatus.textContent = 'Готовий текст звернення скопіюється перед відкриттям Telegram.';
        }
    };

    const setSelected = (id, name, meta, slug = '') => {
        if (profileIdInput) profileIdInput.value = id ? String(id) : '';
        if (id) {
            if (selectedName) selectedName.textContent = name || '';
            if (selectedMeta) selectedMeta.textContent = meta || '';
            if (selectedCard) selectedCard.dataset.profileSlug = slug || '';
            selectedCard?.removeAttribute('hidden');
            if (searchInput) searchInput.value = '';
            syncSupportLink(id, name, slug);
        } else {
            if (selectedCard) selectedCard.dataset.profileSlug = '';
            selectedCard?.setAttribute('hidden', '');
            if (supportLink) {
                supportLink.href = supportBaseUrl();
                supportLink.dataset.supportMessage = '';
            }
        }
        hideSuggest();
        setError('');
    };

    const makeSuggestButton = (item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'hero__search-suggest-item';
        button.dataset.claimSuggestItem = '1';
        button.dataset.profileId = String(item.profile_id || '');
        button.dataset.label = item.label || '';
        button.dataset.meta = item.meta || '';
        button.dataset.profileSlug = item.profile_slug || '';

        const icon = document.createElement('i');
        icon.className = item.icon || 'fa-regular fa-user';
        icon.setAttribute('aria-hidden', 'true');
        button.appendChild(icon);

        const text = document.createElement('span');
        text.className = 'hero__search-suggest-text';
        const label = document.createElement('span');
        label.className = 'hero__search-suggest-label';
        label.textContent = item.label || '';
        text.appendChild(label);
        if (item.meta) {
            const meta = document.createElement('span');
            meta.className = 'hero__search-suggest-meta';
            meta.textContent = item.meta;
            text.appendChild(meta);
        }
        button.appendChild(text);

        return button;
    };

    const renderSuggest = (items, target = suggestBox, options = {}) => {
        if (!target) return;
        target.innerHTML = '';
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = options.emptyClass || 'hero__search-suggest-empty';
            empty.textContent = options.emptyMessage || 'Профілі не знайдено.';
            target.appendChild(empty);
            target.hidden = false;
            return;
        }
        if (options.title) {
            const title = document.createElement('p');
            title.className = 'hero__search-suggest-title';
            title.textContent = options.title;
            target.appendChild(title);
        }
        items.forEach((item) => target.appendChild(makeSuggestButton(item)));
        target.hidden = false;
    };

    const fetchSuggest = async (query, renderTarget = suggestBox, renderOptions = {}) => {
        const requestId = ++activeRequest;
        try {
            const response = await fetch(`${SUGGEST_ENDPOINT}${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(String(response.status));
            const payload = await response.json();
            if (requestId !== activeRequest) return;
            const items = Array.isArray(payload.items)
                ? payload.items.filter((item) => item.type === 'profile' && item.profile_id)
                : [];
            renderSuggest(items, renderTarget, renderOptions);
        } catch {
            if (requestId !== activeRequest) return;
            renderSuggest([], renderTarget, renderOptions);
        }
    };

    const ensureMobileSearchModal = () => {
        if (mobileSearchModal) return mobileSearchModal;

        const modal = document.createElement('div');
        modal.className = 'msearch pro-claim-search-modal';
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Пошук профілю для привʼязки');
        modal.innerHTML = `
            <div class="msearch__bar">
                <button type="button" class="msearch__back" data-claim-mobile-close aria-label="Закрити пошук">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                </button>
                <form class="msearch__form" data-claim-mobile-form>
                    <i class="fa-solid fa-magnifying-glass msearch__icon" aria-hidden="true"></i>
                    <input
                        type="search"
                        class="msearch__input"
                        placeholder="Назва компанії, місто або сайт"
                        autocomplete="off"
                        autocapitalize="off"
                        autocorrect="off"
                        enterkeyhint="search"
                        aria-label="Пошук профілю"
                        data-claim-mobile-input
                    >
                    <button type="button" class="msearch__clear" data-claim-mobile-clear hidden aria-label="Очистити запит">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </form>
            </div>
            <div class="msearch__results" data-claim-mobile-results></div>
        `;
        document.body.appendChild(modal);

        const form = modal.querySelector('[data-claim-mobile-form]');
        const input = modal.querySelector('[data-claim-mobile-input]');
        const results = modal.querySelector('[data-claim-mobile-results]');
        const clear = modal.querySelector('[data-claim-mobile-clear]');
        const close = modal.querySelector('[data-claim-mobile-close]');

        const updateClear = () => {
            if (clear) clear.hidden = !input?.value.trim();
        };

        const loadMobileSuggest = () => {
            if (!input || !results) return;
            const value = input.value.trim();
            window.clearTimeout(mobileDebounceId);
            mobileDebounceId = window.setTimeout(() => {
                updateClear();
                if (!value) {
                    fetchSuggest('', results, { title: 'Популярні профілі' });
                    return;
                }
                if (value.length < 2) {
                    renderSuggest([], results, { emptyMessage: 'Введіть щонайменше 2 символи.' });
                    return;
                }
                fetchSuggest(value, results, { title: 'Результати' });
            }, value ? DEBOUNCE_MS : 0);
        };

        const closeMobileSearch = () => {
            if (!mobileSearchOpen) return;
            mobileSearchOpen = false;
            window.clearTimeout(mobileDebounceId);
            modal.classList.remove('is-open');
            document.body.classList.remove('mobile-search-open');
            input?.blur();
            window.setTimeout(() => {
                modal.hidden = true;
                modal.setAttribute('aria-hidden', 'true');
            }, 220);
        };

        form?.addEventListener('submit', (event) => {
            event.preventDefault();
            const first = results?.querySelector('[data-claim-suggest-item]');
            first?.click();
        });
        input?.addEventListener('input', loadMobileSuggest);
        clear?.addEventListener('click', () => {
            if (!input) return;
            input.value = '';
            loadMobileSuggest();
            input.focus({ preventScroll: true });
        });
        close?.addEventListener('click', closeMobileSearch);
        modal.addEventListener('click', (event) => {
            const item = event.target.closest('[data-claim-suggest-item]');
            if (!item) return;
            setSelected(item.dataset.profileId, item.dataset.label, item.dataset.meta, item.dataset.profileSlug);
            closeMobileSearch();
        });

        mobileSearchModal = {
            open(prefill = '') {
                mobileSearchOpen = true;
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('mobile-search-open');
                if (input) input.value = prefill;
                updateClear();
                loadMobileSuggest();
                modal.classList.add('is-open');
                window.requestAnimationFrame(() => {
                    modal.classList.add('is-open');
                    input?.focus({ preventScroll: true });
                });
            },
            close: closeMobileSearch,
        };

        return mobileSearchModal;
    };

    const openMobileSearch = (event) => {
        if (!isMobileSearchViewport()) return false;
        event?.preventDefault();
        searchInput?.blur();
        ensureMobileSearchModal().open(searchInput?.value || '');
        return true;
    };

    /* ---------------------------------------------------------------- */
    /* Events                                                           */
    /* ---------------------------------------------------------------- */

    wizard.querySelectorAll('[data-claim-flow-pick]').forEach((card) => {
        card.addEventListener('click', () => enterFlow(card.dataset.claimFlowPick));
    });

    backButton?.addEventListener('click', goBack);

    Object.values(flowForms).forEach((form) => {
        if (!form) return;
        form.querySelector('[data-claim-next]')?.addEventListener('click', goNext);
        // Guard submit: re-validate every step of the flow, jump to the first bad one.
        form.addEventListener('submit', (event) => {
            const flow = form.dataset.claimFlow;
            for (const step of stepsOf(flow)) {
                if (!validateStep(step)) {
                    event.preventDefault();
                    // showStep clears errors, so move first (if needed) then re-set.
                    if (currentStep !== step) showStep(step);
                    validateStep(step);
                    return;
                }
            }
        });
    });

    if (searchInput) {
        searchInput.addEventListener('pointerdown', (event) => {
            openMobileSearch(event);
        });
        searchInput.addEventListener('input', () => {
            if (isMobileSearchViewport()) return;
            window.clearTimeout(debounceId);
            const value = searchInput.value.trim();
            if (!value) {
                debounceId = window.setTimeout(() => fetchSuggest('', suggestBox, { title: 'Популярні профілі' }), 0);
                return;
            }
            if (value.length < 2) {
                renderSuggest([], suggestBox, { emptyClass: 'pro-account-claim-search__empty', emptyMessage: 'Введіть щонайменше 2 символи.' });
                return;
            }
            debounceId = window.setTimeout(() => fetchSuggest(value), DEBOUNCE_MS);
        });
        searchInput.addEventListener('focus', (event) => {
            if (openMobileSearch(event)) return;
            const value = searchInput.value.trim();
            if (!value) fetchSuggest('', suggestBox, { title: 'Популярні профілі' });
            else if (value.length >= 2) fetchSuggest(value);
        });
    }

    suggestBox?.addEventListener('click', (event) => {
        const item = event.target.closest('[data-claim-suggest-item]');
        if (!item) return;
        setSelected(item.dataset.profileId, item.dataset.label, item.dataset.meta, item.dataset.profileSlug);
    });

    clearButton?.addEventListener('click', () => {
        setSelected('', '', '');
        window.setTimeout(() => searchInput?.focus(), 30);
    });

    supportLink?.addEventListener('click', () => {
        const message = supportLink.dataset.supportMessage || '';
        if (!message || !navigator.clipboard?.writeText) return;
        navigator.clipboard.writeText(message)
            .then(() => {
                if (supportStatus) supportStatus.textContent = 'Текст звернення скопійовано. Вставте його в чат підтримки.';
            })
            .catch(() => {
                if (supportStatus) supportStatus.textContent = 'Telegram відкрито. Надішліть назву профілю підтримці.';
            });
    });

    document.addEventListener('click', (event) => {
        if (!claimForm || suggestBox?.hidden) return;
        if (event.target.closest('[data-claim-search]')) return;
        hideSuggest();
    });

    /* ---------------------------------------------------------------- */
    /* Init — honour the server-computed starting point                 */
    /* ---------------------------------------------------------------- */

    const initialFlow = wizard.dataset.claimInitialFlow || '';
    const initialStep = wizard.dataset.claimInitialStep || 'scenario';

    if (initialFlow && FLOWS[initialFlow]) {
        syncSupportLink(profileIdInput?.value || '', selectedName?.textContent || '', selectedCard?.dataset.profileSlug || '');
        enterFlow(initialFlow, stepsOf(initialFlow).includes(initialStep) ? initialStep : stepsOf(initialFlow)[0]);
    } else {
        goToScenario();
    }
})();
