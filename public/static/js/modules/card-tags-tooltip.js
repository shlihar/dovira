/* Fit-as-many-as-fit tags row: the server renders up to 3 candidate chips
   (shortest first); this measures the actual row width and hides whatever
   doesn't fit, folding the rest into a "+N" badge — the row never wraps to
   a second line. Also toggles the "+N" tooltip trigger (below) to match. */
(() => {
    const GAP = 6; // must match .dovira-catalog-card__tags gap in CSS

    const fitTagsRow = (row) => {
        const totalCount = Number(row.dataset.totalServices || 0);
        const source = row.dataset.cardTagsSource || '';
        const chips = Array.from(row.querySelectorAll('.dovira-catalog-card__tag:not(.dovira-catalog-card__tag--more)'));
        const moreChip = row.querySelector('.dovira-catalog-card__tag--more');
        if (!chips.length || !moreChip) return;

        // Make everything visible so natural (unclamped) widths can be read.
        chips.forEach((chip) => { chip.hidden = false; });
        moreChip.hidden = false;

        const available = row.clientWidth;
        if (!available) return; // not laid out yet (e.g. a hidden carousel slide)

        const rowWidth = (count, withMore) => {
            let sum = 0;
            for (let i = 0; i < count; i += 1) {
                sum += chips[i].getBoundingClientRect().width + (i > 0 ? GAP : 0);
            }
            if (withMore) sum += (count > 0 ? GAP : 0) + moreChip.getBoundingClientRect().width;
            return sum;
        };

        const needsMore = chips.length < totalCount;

        if (!needsMore && rowWidth(chips.length, false) <= available) {
            moreChip.hidden = true;
            row.removeAttribute('data-card-tags');
            row.removeAttribute('role');
            row.removeAttribute('tabindex');
            return;
        }

        let shown = chips.length;
        while (shown > 0) {
            moreChip.textContent = `+${totalCount - shown}`;
            if (rowWidth(shown, true) <= available) break;
            shown -= 1;
        }
        if (shown === 0) moreChip.textContent = `+${totalCount}`;

        chips.forEach((chip, i) => { chip.hidden = i >= shown; });
        moreChip.hidden = false;
        if (source) {
            row.setAttribute('data-card-tags', source);
            row.setAttribute('role', 'button');
            row.setAttribute('tabindex', '0');
        }
    };

    const fitAllTagRows = (root = document) => {
        (root.querySelectorAll ? root : document)
            .querySelectorAll('[data-total-services]')
            .forEach(fitTagsRow);
    };

    fitAllTagRows(document);
    if (document.fonts?.ready) {
        // Chip widths depend on the real webfont — redo the fit once it's
        // loaded in case the fallback font measured differently.
        document.fonts.ready.then(() => fitAllTagRows(document)).catch(() => {});
    }

    let resizeId = 0;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeId);
        resizeId = window.setTimeout(() => fitAllTagRows(document), 150);
    }, { passive: true });

    window.DoviraFitCardTags = fitAllTagRows;
})();

/* Directions tooltip for profile cards.
   Hover (pointer devices) or tap (touch) a "+N" tags row to see every
   direction/service in a small floating popover. Event-delegated so it keeps
   working after the catalog AJAX swaps its cards. */
(() => {
    let tip = null;
    let currentTrigger = null;
    let hideTimer = 0;

    const getTip = () => {
        if (tip) return tip;
        tip = document.createElement('div');
        tip.className = 'card-tags-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);
        return tip;
    };

    const renderTip = (trigger) => {
        const raw = trigger.getAttribute('data-card-tags') || '';
        const items = raw.split('|').map((s) => s.trim()).filter(Boolean);
        if (!items.length) return null;

        const node = getTip();
        node.innerHTML = '';
        const title = document.createElement('p');
        title.className = 'card-tags-tip__title';
        title.textContent = 'Напрями';
        node.appendChild(title);

        const list = document.createElement('div');
        list.className = 'card-tags-tip__list';
        items.forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'card-tags-tip__chip';
            chip.textContent = item;
            list.appendChild(chip);
        });
        node.appendChild(list);
        return node;
    };

    const positionTip = (trigger, node) => {
        node.hidden = false;
        node.classList.remove('is-visible');

        const rect = trigger.getBoundingClientRect();
        const margin = 8;
        const tipRect = node.getBoundingClientRect();
        const width = tipRect.width;

        let left = rect.left + rect.width / 2 - width / 2;
        left = Math.max(margin, Math.min(left, window.innerWidth - width - margin));

        // Prefer below; flip above if it would go off-screen.
        let top = rect.bottom + 8;
        node.classList.remove('card-tags-tip--above');
        if (top + tipRect.height > window.innerHeight - margin && rect.top - tipRect.height - 8 > margin) {
            top = rect.top - tipRect.height - 8;
            node.classList.add('card-tags-tip--above');
        }

        node.style.left = `${Math.round(left)}px`;
        node.style.top = `${Math.round(top)}px`;
        window.requestAnimationFrame(() => node.classList.add('is-visible'));
    };

    const show = (trigger) => {
        window.clearTimeout(hideTimer);
        if (currentTrigger === trigger && tip && !tip.hidden) return;
        const node = renderTip(trigger);
        if (!node) return;
        currentTrigger = trigger;
        positionTip(trigger, node);
    };

    const hide = () => {
        if (!tip) return;
        tip.classList.remove('is-visible');
        currentTrigger = null;
        window.clearTimeout(hideTimer);
        hideTimer = window.setTimeout(() => { if (tip) tip.hidden = true; }, 160);
    };

    // Desktop: hover.
    document.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch') return;
        const trigger = event.target.closest('[data-card-tags]');
        if (trigger) show(trigger);
        else if (currentTrigger && !event.target.closest('.card-tags-tip')) hide();
    });

    // Keyboard: Enter/Space toggles, same as a real button (a plain
    // role="button" div doesn't get native activation from those keys).
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const trigger = event.target.closest('[data-card-tags]');
        if (!trigger) return;
        event.preventDefault();
        if (currentTrigger === trigger && tip && !tip.hidden) hide();
        else show(trigger);
    });

    // Touch / click: toggle. (Tapping also fires `focusin` first on some
    // browsers — this handler is the single source of truth, so it doesn't
    // fight with a focus-triggered open.)
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-card-tags]');
        if (trigger) {
            event.preventDefault();
            event.stopPropagation();
            if (currentTrigger === trigger && tip && !tip.hidden) hide();
            else show(trigger);
            return;
        }
        if (currentTrigger && !event.target.closest('.card-tags-tip')) hide();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });

    window.addEventListener('scroll', hide, { passive: true, capture: true });
    window.addEventListener('resize', hide, { passive: true });
})();
