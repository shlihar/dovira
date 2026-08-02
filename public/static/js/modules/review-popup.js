(() => {
    const popup = document.querySelector('[data-review-popup]');
    if (!popup) return;

    const form = popup.querySelector('[data-review-popup-form]');
    const closeButtons = popup.querySelectorAll('[data-review-popup-close]');
    const searchShell = popup.querySelector('[data-review-profile-search-wrap]');
    const profileInput = popup.querySelector('[data-review-profile-input]');
    const profileSlugInput = popup.querySelector('[data-review-profile-slug]');
    const suggest = popup.querySelector('[data-review-profile-suggest]');
    const selectedProfile = popup.querySelector('[data-review-selected-profile]');
    const selectedProfileName = popup.querySelector('[data-review-selected-profile-name]');
    const clearProfileButton = popup.querySelector('[data-review-clear-profile]');
    const popupTitle = popup.querySelector('[data-review-popup-title]');
    const popupLead = popup.querySelector('[data-review-popup-lead]');
    const popupSubmitLabel = popup.querySelector('[data-review-popup-submit-label]');
    const ratingInput = popup.querySelector('[data-review-rating-input]');
    const ratingValueNode = popup.querySelector('[data-review-rating-value]');
    const ratingButtons = Array.from(popup.querySelectorAll('[data-rating-value]'));
    const errorNode = popup.querySelector('[data-review-error]');
    const successNode = popup.querySelector('[data-review-success]');
    const mediaInput = popup.querySelector('[data-review-media-input]');
    const mediaNote = popup.querySelector('[data-review-media-note]');
    const mediaPreview = popup.querySelector('[data-review-media-preview]');

    const steps = Array.from(popup.querySelectorAll('[data-review-step]'));
    const progressDots = Array.from(popup.querySelectorAll('[data-review-progress-dot]'));
    const backButton = popup.querySelector('[data-review-back]');
    const nextButton = popup.querySelector('[data-review-next]');
    const submitButton = popup.querySelector('[data-review-submit]');
    const upsell = popup.querySelector('[data-review-upsell]');
    const upsellRegister = popup.querySelector('[data-review-upsell-register]');
    const bodyEl = popup.querySelector('[data-review-popup-body]');
    const footEl = popup.querySelector('.review-popup__foot');
    const progressEl = popup.querySelector('[data-review-progress]');

    const isAuthenticated = document.body.dataset.authenticated === '1';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const DRAFT_KEY = 'dovira.review.popup.draft.v1';
    const SUGGEST_ENDPOINT = '/search/suggest?scope=profiles&limit=8&q=';
    const DEBOUNCE_MS = 170;
    const TOTAL_STEPS = steps.length || 3;

    const cache = new Map();
    let debounceId = 0;
    let activeIndex = -1;
    let activeRequest = 0;
    let popupMode = 'create';
    let editReviewId = null;
    let currentStep = 1;
    let mediaPreviewUrls = [];
    let selectedMediaFiles = [];
    let closeTimer = 0;

    if (!form || !profileInput || !profileSlugInput || !suggest || !ratingInput) {
        return;
    }

    /* ---------------------------------------------------------------- */
    /* Draft & recent profiles                                          */
    /* ---------------------------------------------------------------- */

    const getDraft = () => {
        try {
            const raw = window.sessionStorage.getItem(DRAFT_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    };

    const setDraft = () => {
        if (popupMode === 'edit') return;
        try {
            window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify({
                profile_slug: profileSlugInput.value || '',
                profile_name: selectedProfileName?.textContent || profileInput.value || '',
                rating: ratingInput.value || '0',
                author_name: form.elements.author_name?.value || '',
                author_email: form.elements.author_email?.value || '',
                body: form.elements.body?.value || '',
                updated_at: Date.now(),
            }));
        } catch {}
    };

    const clearDraft = () => {
        try { window.sessionStorage.removeItem(DRAFT_KEY); } catch {}
    };

    /* ---------------------------------------------------------------- */
    /* Small UI helpers                                                 */
    /* ---------------------------------------------------------------- */

    // Inline error slots live inside each step, right under the relevant
    // field — so the message stays above the mobile keyboard instead of the
    // dialog-level error strip that sits below the fold and gets covered.
    const stepErrorNodes = Array.from(popup.querySelectorAll('[data-review-step-error]'));

    const getStepErrorNode = () => stepErrorNodes.find(
        (node) => Number(node.closest('[data-review-step]')?.dataset.reviewStep) === currentStep
    );

    const setError = (message = '') => {
        stepErrorNodes.forEach((node) => { node.textContent = ''; node.hidden = true; });
        if (errorNode) { errorNode.textContent = ''; errorNode.hidden = true; }
        if (!message) return;

        const target = getStepErrorNode() || errorNode;
        if (!target) return;
        target.textContent = message;
        target.hidden = false;
        window.requestAnimationFrame(() => target.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
    };

    const setSuccess = (message = '') => {
        if (!successNode) return;
        successNode.textContent = message;
        successNode.hidden = message === '';
    };

    const hideSuggest = () => {
        suggest.hidden = true;
        activeIndex = -1;
    };

    /* ---------------------------------------------------------------- */
    /* Media                                                            */
    /* ---------------------------------------------------------------- */

    const clearMediaPreview = () => {
        mediaPreviewUrls.forEach((url) => { try { URL.revokeObjectURL(url); } catch {} });
        mediaPreviewUrls = [];
        selectedMediaFiles = [];
        if (mediaInput) mediaInput.value = '';
        if (mediaPreview) { mediaPreview.innerHTML = ''; mediaPreview.hidden = true; }
        if (mediaNote) mediaNote.textContent = '';
    };

    const syncMediaInputFiles = () => {
        if (!mediaInput) return;
        const transfer = new DataTransfer();
        selectedMediaFiles.forEach((file) => transfer.items.add(file));
        mediaInput.files = transfer.files;
    };

    const renderMediaPreview = () => {
        if (!mediaInput || !mediaPreview) return;
        mediaPreviewUrls.forEach((url) => { try { URL.revokeObjectURL(url); } catch {} });
        mediaPreviewUrls = [];
        mediaPreview.innerHTML = '';

        if (!selectedMediaFiles.length) {
            mediaPreview.hidden = true;
            if (mediaNote) mediaNote.textContent = '';
            return;
        }

        const fragment = document.createDocumentFragment();
        selectedMediaFiles.forEach((file, index) => {
            const previewUrl = URL.createObjectURL(file);
            mediaPreviewUrls.push(previewUrl);

            const item = document.createElement('div');
            item.className = 'review-popup__media-preview-item';

            if (String(file.type || '').startsWith('video/')) {
                const video = document.createElement('video');
                video.src = previewUrl;
                video.muted = true;
                video.preload = 'metadata';
                video.playsInline = true;
                item.appendChild(video);
                const badge = document.createElement('span');
                badge.className = 'review-popup__media-preview-badge';
                badge.innerHTML = '<i class="fa-solid fa-play" aria-hidden="true"></i><span>Відео</span>';
                item.appendChild(badge);
            } else {
                const image = document.createElement('img');
                image.src = previewUrl;
                image.alt = file.name || 'Обране зображення';
                image.loading = 'lazy';
                item.appendChild(image);
            }

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'review-popup__media-preview-remove';
            removeButton.setAttribute('aria-label', 'Видалити файл');
            removeButton.dataset.reviewMediaRemove = String(index);
            removeButton.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
            item.appendChild(removeButton);

            fragment.appendChild(item);
        });

        mediaPreview.appendChild(fragment);
        mediaPreview.hidden = false;
        if (mediaNote) mediaNote.textContent = `Обрано файлів: ${selectedMediaFiles.length}`;
    };

    /* ---------------------------------------------------------------- */
    /* Profile selection                                               */
    /* ---------------------------------------------------------------- */

    const setSelected = (slug = '', name = '') => {
        profileSlugInput.value = slug;
        // Keep the picked profile in a single place — the confirmation card —
        // instead of also leaving the name inside the search field.
        profileInput.value = '';
        if (slug && name) {
            searchShell?.setAttribute('hidden', '');
            selectedProfile?.removeAttribute('hidden');
            if (selectedProfileName) selectedProfileName.textContent = name;
        } else {
            searchShell?.removeAttribute('hidden');
            selectedProfile?.setAttribute('hidden', '');
            if (selectedProfileName) selectedProfileName.textContent = '';
        }
        hideSuggest();
        setDraft();
    };

    const setRating = (value) => {
        const numeric = Number(value);
        const rating = Number.isFinite(numeric) ? Math.max(0, Math.min(5, numeric)) : 0;
        ratingInput.value = String(rating);
        if (ratingValueNode) ratingValueNode.textContent = rating > 0 ? `${rating}.0` : '';
        ratingButtons.forEach((button) => {
            const buttonRate = Number(button.dataset.ratingValue) || 0;
            button.classList.toggle('is-on', rating > 0 && buttonRate <= rating);
        });
        setDraft();
    };

    /* ---------------------------------------------------------------- */
    /* Suggestions                                                     */
    /* ---------------------------------------------------------------- */

    const buildSuggestButton = (item, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'hero__search-suggest-item review-popup__suggest-item';
        button.dataset.index = String(index);
        button.dataset.slug = item.profile_slug || '';
        button.dataset.label = item.label || '';

        const icon = document.createElement('i');
        icon.className = item.icon || 'fa-regular fa-user';
        icon.setAttribute('aria-hidden', 'true');

        const wrap = document.createElement('span');
        wrap.className = 'hero__search-suggest-text';
        const label = document.createElement('span');
        label.className = 'hero__search-suggest-label';
        label.textContent = item.label || '';
        wrap.appendChild(label);

        if (item.meta) {
            const meta = document.createElement('small');
            meta.className = 'hero__search-suggest-meta';
            meta.textContent = item.meta;
            wrap.appendChild(meta);
        }

        button.appendChild(icon);
        button.appendChild(wrap);
        return button;
    };

    const renderSuggest = (items, title = 'Оберіть профіль', emptyMessage = 'Профілі не знайдено') => {
        suggest.innerHTML = '';
        const titleNode = document.createElement('p');
        titleNode.className = 'hero__search-suggest-title';
        titleNode.textContent = title;
        suggest.appendChild(titleNode);

        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'hero__search-suggest-empty';
            empty.textContent = emptyMessage;
            suggest.appendChild(empty);
            suggest.hidden = false;
            return;
        }

        items.forEach((item, index) => suggest.appendChild(buildSuggestButton(item, index)));
        suggest.hidden = false;
        activeIndex = -1;
    };

    async function fetchSuggest(query, options = {}) {
        const { force = false } = options;
        const raw = String(query || '');
        const normalized = raw.trim().toLowerCase();

        if (normalized.length === 0 && !force) {
            hideSuggest();
            return;
        }
        if (cache.has(normalized)) {
            renderSuggest(cache.get(normalized) || [], raw.trim() ? `Результати для "${raw.trim()}"` : 'Оберіть профіль');
            return;
        }

        const requestId = ++activeRequest;
        try {
            const response = await fetch(`${SUGGEST_ENDPOINT}${encodeURIComponent(raw)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`Suggest failed: ${response.status}`);
            const payload = await response.json();
            if (requestId !== activeRequest) return;
            const items = Array.isArray(payload.items)
                ? payload.items.filter((item) => item.type === 'profile' && item.profile_slug)
                : [];
            cache.set(normalized, items);
            renderSuggest(items, raw.trim() ? `Результати для "${raw.trim()}"` : 'Оберіть профіль');
        } catch {
            if (requestId !== activeRequest) return;
            renderSuggest([], raw.trim() ? `Результати для "${raw.trim()}"` : 'Оберіть профіль');
        }
    }

    /* ---------------------------------------------------------------- */
    /* Wizard                                                          */
    /* ---------------------------------------------------------------- */

    const setPopupMode = (mode = 'create') => {
        popupMode = mode === 'edit' ? 'edit' : 'create';
        if (popupMode === 'edit') {
            if (popupTitle) popupTitle.textContent = 'Редагувати відгук';
            if (popupLead) popupLead.textContent = 'Оновіть оцінку або текст вашого відгуку.';
            if (popupSubmitLabel) popupSubmitLabel.textContent = 'Зберегти';
            clearProfileButton?.setAttribute('hidden', '');
        } else {
            if (popupTitle) popupTitle.textContent = 'Додати відгук';
            if (popupLead) popupLead.textContent = 'Оберіть профіль, поставте оцінку та поділіться досвідом.';
            if (popupSubmitLabel) popupSubmitLabel.textContent = 'Опублікувати';
            clearProfileButton?.removeAttribute('hidden');
        }
    };

    const showStep = (step) => {
        currentStep = Math.max(1, Math.min(TOTAL_STEPS, step));
        steps.forEach((section) => {
            const isCurrent = Number(section.dataset.reviewStep) === currentStep;
            section.classList.toggle('is-active', isCurrent);
            section.hidden = !isCurrent;
        });
        progressDots.forEach((dot) => {
            const n = Number(dot.dataset.reviewProgressDot);
            dot.classList.toggle('is-active', n === currentStep);
            dot.classList.toggle('is-done', n < currentStep);
        });

        if (backButton) backButton.hidden = currentStep === 1;
        const onLast = currentStep === TOTAL_STEPS;
        if (nextButton) nextButton.hidden = onLast;
        if (submitButton) submitButton.hidden = !onLast;

        setError('');
    };

    const validateStep = (step) => {
        if (step === 1) {
            if (!profileSlugInput.value) {
                setError('Оберіть профіль зі списку.');
                profileInput.focus();
                return false;
            }
            if (Number(ratingInput.value || 0) < 1) {
                setError('Поставте оцінку від 1 до 5.');
                return false;
            }
            return true;
        }
        if (step === 2) {
            // Текст необов'язковий — досить самої оцінки з кроку 1.
            return true;
        }
        return true;
    };

    const goNext = () => {
        if (!validateStep(currentStep)) return;
        if (currentStep < TOTAL_STEPS) showStep(currentStep + 1);
    };

    const goBack = () => {
        if (currentStep > 1) showStep(currentStep - 1);
    };

    /* ---------------------------------------------------------------- */
    /* Open / close                                                    */
    /* ---------------------------------------------------------------- */

    // Post-publish "thank you" state: hide the wizard, reveal the soft
    // registration upsell for guests.
    const showDoneState = () => {
        if (bodyEl) bodyEl.hidden = true;
        if (footEl) footEl.hidden = true;
        if (progressEl) progressEl.hidden = true;
        if (upsell) upsell.hidden = false;
        if (popupTitle) popupTitle.textContent = 'Дякуємо за відгук!';
        if (popupLead) popupLead.textContent = 'Він з\'явиться на сторінці профілю після перевірки.';
    };

    const resetDoneState = () => {
        if (bodyEl) bodyEl.hidden = false;
        if (footEl) footEl.hidden = false;
        if (progressEl) progressEl.hidden = false;
        if (upsell) upsell.hidden = true;
    };

    // Lock the page behind the popup without losing the scroll position
    // (plain `overflow:hidden` jumps to the top on mobile when focusing a field).
    let lockedScrollY = 0;
    let scrollLocked = false;

    const lockScroll = () => {
        if (scrollLocked) return;
        lockedScrollY = window.scrollY || window.pageYOffset || 0;
        document.body.style.position = 'fixed';
        document.body.style.top = `-${lockedScrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        scrollLocked = true;
    };

    const unlockScroll = () => {
        if (!scrollLocked) return;
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.left = '';
        document.body.style.right = '';
        document.body.style.width = '';
        scrollLocked = false;
        const prev = document.documentElement.style.scrollBehavior;
        document.documentElement.style.scrollBehavior = 'auto';
        window.scrollTo(0, lockedScrollY);
        document.documentElement.style.scrollBehavior = prev;
    };

    const openPopup = (prefill = null) => {
        window.clearTimeout(closeTimer);
        popup.hidden = false;
        popup.classList.remove('is-closing');
        lockScroll();
        window.requestAnimationFrame(() => popup.classList.add('is-open'));
        document.body.classList.add('is-review-popup-open');
        resetDoneState();
        setError('');
        setSuccess('');
        setPopupMode(prefill?.mode === 'edit' ? 'edit' : 'create');
        editReviewId = prefill?.reviewId || null;

        if (prefill?.slug && prefill?.name) {
            setSelected(prefill.slug, prefill.name);
        } else {
            const draft = getDraft();
            if (draft?.profile_slug && draft?.profile_name) {
                setSelected(draft.profile_slug, draft.profile_name);
            } else {
                setSelected('', '');
            }
        }

        if (prefill?.mode === 'edit') {
            if (typeof prefill.rating !== 'undefined') setRating(prefill.rating);
            if (form.elements.body && typeof prefill.body === 'string') form.elements.body.value = prefill.body;
            if (form.elements.author_name && typeof prefill.author_name === 'string') form.elements.author_name.value = prefill.author_name;
            if (form.elements.author_email && typeof prefill.author_email === 'string') form.elements.author_email.value = prefill.author_email;
        }

        showStep(1);
        if (!prefill?.slug && !profileSlugInput.value) {
            window.setTimeout(() => profileInput.focus(), 60);
        }
    };

    const closePopup = () => {
        window.clearTimeout(closeTimer);
        popup.classList.remove('is-open');
        popup.classList.add('is-closing');
        document.body.classList.remove('is-review-popup-open');
        hideSuggest();
        setError('');
        setPopupMode('create');
        editReviewId = null;
        unlockScroll();
        closeTimer = window.setTimeout(() => {
            popup.hidden = true;
            popup.classList.remove('is-closing');
        }, 200);
    };

    const applyDraft = () => {
        const draft = getDraft();
        if (!draft) {
            setRating(0);
            return;
        }
        if (draft.profile_slug && draft.profile_name) setSelected(draft.profile_slug, draft.profile_name);
        if (form.elements.author_name && typeof draft.author_name === 'string') form.elements.author_name.value = draft.author_name;
        if (form.elements.author_email && typeof draft.author_email === 'string') form.elements.author_email.value = draft.author_email;
        if (form.elements.body && typeof draft.body === 'string') form.elements.body.value = draft.body;
        setRating(draft.rating || 0);
    };

    /* ---------------------------------------------------------------- */
    /* Submit                                                          */
    /* ---------------------------------------------------------------- */

    const submitReview = async () => {
        setError('');
        setSuccess('');

        if (submitButton) submitButton.disabled = true;
        try {
            syncMediaInputFiles();
            const payload = new FormData(form);
            payload.set('profile_slug', profileSlugInput.value);
            payload.set('rating', ratingInput.value);
            if (popupMode === 'edit') payload.set('_method', 'PATCH');

            const endpoint = popupMode === 'edit' && editReviewId
                ? `/profile/reviews/${encodeURIComponent(editReviewId)}`
                : '/reviews';

            const response = await fetch(endpoint, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: payload,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
                throw new Error(firstError || data?.message || 'Не вдалося надіслати відгук. Спробуйте ще раз.');
            }

            const data = await response.json().catch(() => ({}));
            window.doviraTrack?.('review_submit_success', profileSlugInput.value || '');
            setSuccess(data.message || 'Відгук надіслано на модерацію.');
            if (popupMode !== 'edit') clearDraft();
            if (form.elements.body) form.elements.body.value = '';
            if (form.elements.author_name) form.elements.author_name.value = '';
            if (form.elements.author_email) form.elements.author_email.value = '';
            clearMediaPreview();
            setRating(0);

            if (popupMode === 'edit') {
                window.setTimeout(() => window.location.reload(), 700);
            } else if (isAuthenticated) {
                window.setTimeout(closePopup, 1100);
            } else {
                // Guests: keep the popup open on a clean "thank you" screen with
                // a soft nudge to register — no forced account, no auto-close.
                showDoneState();
            }
        } catch (error) {
            window.doviraTrack?.('review_submit_error', profileSlugInput.value || '');
            setError(error.message || 'Не вдалося надіслати відгук.');
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    };

    const requestPublish = () => {
        // Make sure earlier steps are valid even if the user jumped ahead.
        if (!validateStep(1)) { showStep(1); return; }
        if (!validateStep(2)) { showStep(2); return; }

        // No account required — guests and members publish the same way.
        // Anti-spam is handled server-side (Turnstile + auto-moderation).
        submitReview();
    };

    const goRegister = () => {
        setDraft();
        const returnUrl = new URL(window.location.href);
        returnUrl.searchParams.set('review_popup', '1');
        window.location.href = `/register?next=${encodeURIComponent(returnUrl.toString())}`;
    };

    /* ---------------------------------------------------------------- */
    /* Events                                                          */
    /* ---------------------------------------------------------------- */

    // Delegated so triggers inside AJAX-swapped content (catalog results,
    // "залишити перший відгук" links on cards) keep working after re-renders.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-open-review-popup]');
        if (!button) return;
        event.preventDefault();
        if (button.getAttribute('data-review-mode') === 'edit') {
            openPopup({
                mode: 'edit',
                reviewId: button.getAttribute('data-review-id'),
                slug: button.getAttribute('data-profile-slug') || '',
                name: button.getAttribute('data-profile-name') || '',
                rating: Number(button.getAttribute('data-review-rating') || '5'),
                body: button.getAttribute('data-review-body') || '',
            });
            return;
        }
        const slug = button.getAttribute('data-profile-slug');
        const name = button.getAttribute('data-profile-name');
        openPopup(slug && name ? { slug, name } : null);
    });

    closeButtons.forEach((button) => button.addEventListener('click', closePopup));

    // Пряме запрошення на відгук (?review=1): бізнес шле клієнту посилання
    // /r/{slug} — на сторінці профілю одразу відкриваємо форму відгуку.
    if (new URLSearchParams(window.location.search).get('review') === '1') {
        const inviteTrigger = document.querySelector('[data-open-review-popup][data-profile-slug]');
        const inviteSlug = inviteTrigger?.getAttribute('data-profile-slug');
        const inviteName = inviteTrigger?.getAttribute('data-profile-name');
        if (inviteSlug && inviteName) {
            window.setTimeout(() => openPopup({ slug: inviteSlug, name: inviteName }), 350);
        }
    }

    nextButton?.addEventListener('click', goNext);
    backButton?.addEventListener('click', goBack);

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (currentStep < TOTAL_STEPS) {
            goNext();
            return;
        }
        requestPublish();
    });

    upsellRegister?.addEventListener('click', goRegister);

    // Profile search
    profileInput.addEventListener('focus', () => {
        if (popupMode === 'edit') return;
        const value = profileInput.value.trim();
        if (value.length > 0) fetchSuggest(value);
    });

    profileInput.addEventListener('input', () => {
        if (popupMode === 'edit') return;
        profileSlugInput.value = '';
        setDraft();
        if (debounceId) window.clearTimeout(debounceId);
        debounceId = window.setTimeout(() => {
            const value = profileInput.value.trim();
            if (value.length > 0) fetchSuggest(value);
            else hideSuggest();
        }, DEBOUNCE_MS);
    });

    profileInput.addEventListener('keydown', (event) => {
        const items = Array.from(suggest.querySelectorAll('.hero__search-suggest-item'));
        if (!items.length) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            activeIndex = Math.min(items.length - 1, activeIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = Math.max(0, activeIndex - 1);
        } else if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            items[activeIndex]?.click();
            return;
        } else {
            return;
        }
        items.forEach((item, idx) => item.classList.toggle('is-active', idx === activeIndex));
    });

    suggest.addEventListener('click', (event) => {
        const item = event.target.closest('.hero__search-suggest-item');
        if (!item) return;
        setSelected(item.dataset.slug || '', item.dataset.label || '');
        setError('');
    });

    document.addEventListener('click', (event) => {
        if (popup.hidden) return;
        if (suggest.hidden) return;
        if (event.target.closest('[data-review-profile-search], [data-review-profile-suggest]')) return;
        hideSuggest();
    });

    clearProfileButton?.addEventListener('click', () => {
        if (popupMode === 'edit') return;
        setSelected('', '');
        window.setTimeout(() => profileInput.focus(), 30);
    });

    mediaInput?.addEventListener('change', () => {
        selectedMediaFiles = Array.from(mediaInput.files || []).slice(0, 6);
        syncMediaInputFiles();
        renderMediaPreview();
    });

    mediaPreview?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-review-media-remove]');
        if (!button) return;
        const index = Number(button.dataset.reviewMediaRemove);
        if (!Number.isInteger(index) || index < 0) return;
        selectedMediaFiles = selectedMediaFiles.filter((_, i) => i !== index);
        syncMediaInputFiles();
        renderMediaPreview();
    });

    ratingButtons.forEach((button) => {
        button.addEventListener('click', () => setRating(button.dataset.ratingValue || 5));
    });

    form.querySelectorAll('[data-review-input]').forEach((input) => {
        input.addEventListener('input', setDraft);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || popup.hidden) return;
        if (!suggest.hidden) { hideSuggest(); return; }
        closePopup();
    });

    applyDraft();

    const url = new URL(window.location.href);
    if (url.searchParams.get('review_popup') === '1') {
        openPopup();
        url.searchParams.delete('review_popup');
        window.history.replaceState({}, '', url.toString());
    }
})();
