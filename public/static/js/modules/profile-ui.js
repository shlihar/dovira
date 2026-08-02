const isElement = (value) => value instanceof Element || value instanceof Document;

const collectNodes = (root, selector) => {
    if (!isElement(root)) return [];

    const nodes = [];

    if (root instanceof Element && root.matches(selector)) {
        nodes.push(root);
    }

    root.querySelectorAll(selector).forEach((node) => nodes.push(node));

    return nodes;
};

(() => {
    const detailsNodes = document.querySelectorAll('.profile-summary-ai__details');
    if (!detailsNodes.length) return;

    detailsNodes.forEach((details) => {
        const summary = details.querySelector('summary');
        const content = details.querySelector('.profile-summary-ai__text');
        if (!summary || !content) return;

        const closeContent = () => {
            content.style.maxHeight = `${content.scrollHeight}px`;
            requestAnimationFrame(() => {
                details.classList.remove('is-open');
                content.style.maxHeight = '0px';
            });

            const onCloseEnd = (event) => {
                if (event.target !== content || event.propertyName !== 'max-height') return;
                details.open = false;
                content.removeEventListener('transitionend', onCloseEnd);
            };

            content.addEventListener('transitionend', onCloseEnd);
        };

        const openContent = () => {
            details.open = true;
            details.classList.add('is-open');
            content.style.maxHeight = '0px';

            requestAnimationFrame(() => {
                content.style.maxHeight = `${content.scrollHeight}px`;
            });

            const onOpenEnd = (event) => {
                if (event.target !== content || event.propertyName !== 'max-height') return;
                content.style.maxHeight = 'none';
                content.removeEventListener('transitionend', onOpenEnd);
            };

            content.addEventListener('transitionend', onOpenEnd);
        };

        if (details.open) {
            details.classList.add('is-open');
            content.style.maxHeight = 'none';
        } else {
            details.classList.remove('is-open');
            content.style.maxHeight = '0px';
        }

        summary.addEventListener('click', (event) => {
            event.preventDefault();
            if (details.classList.contains('is-open')) {
                closeContent();
                return;
            }

            openContent();
        });
    });
})();

const hashSeed = (input) => {
    let value = 0;

    for (let index = 0; index < input.length; index += 1) {
        value = (value << 5) - value + input.charCodeAt(index);
        value |= 0;
    }

    return Math.abs(value);
};

const avatarPalettes = [
    { bg1: '#eaf2ff', bg2: '#1f68ff', ring: 'rgba(31, 104, 255, 0.22)', glow: 'rgba(31, 104, 255, 0.26)' },
    { bg1: '#e8f5ff', bg2: '#0f4fb8', ring: 'rgba(15, 79, 184, 0.22)', glow: 'rgba(15, 79, 184, 0.26)' },
    { bg1: '#edf0ff', bg2: '#3b5bff', ring: 'rgba(59, 91, 255, 0.20)', glow: 'rgba(59, 91, 255, 0.24)' },
    { bg1: '#eefcff', bg2: '#1483ff', ring: 'rgba(20, 131, 255, 0.18)', glow: 'rgba(20, 131, 255, 0.22)' },
    { bg1: '#e8fffb', bg2: '#0d86c6', ring: 'rgba(13, 134, 198, 0.18)', glow: 'rgba(13, 134, 198, 0.22)' },
    { bg1: '#eaf1ff', bg2: '#2f6df4', ring: 'rgba(47, 109, 244, 0.20)', glow: 'rgba(47, 109, 244, 0.24)' },
    { bg1: '#eaf6ff', bg2: '#0b63d6', ring: 'rgba(11, 99, 214, 0.20)', glow: 'rgba(11, 99, 214, 0.24)' },
    { bg1: '#eff7ff', bg2: '#1b86ff', ring: 'rgba(27, 134, 255, 0.18)', glow: 'rgba(27, 134, 255, 0.22)' },
    { bg1: '#e9f3ff', bg2: '#2753d9', ring: 'rgba(39, 83, 217, 0.20)', glow: 'rgba(39, 83, 217, 0.24)' },
    { bg1: '#e8f0ff', bg2: '#3a66ff', ring: 'rgba(58, 102, 255, 0.18)', glow: 'rgba(58, 102, 255, 0.22)' },
];

const tonePalettes = [
    { accent: '#3f6fe7', top: '#eef3ff', bottom: '#e7efff', border: '#d3e0ff', shadow: '0 6px 12px rgba(63, 111, 231, 0.14)' },
    { accent: '#59c692', top: '#eefcf4', bottom: '#e5f7ee', border: '#ccefdc', shadow: '0 6px 12px rgba(89, 198, 146, 0.14)' },
    { accent: '#8f62da', top: '#f4eefc', bottom: '#eee6fa', border: '#e3d7f7', shadow: '0 6px 12px rgba(143, 98, 218, 0.14)' },
    { accent: '#f3a41b', top: '#fff7e9', bottom: '#fff1db', border: '#fbe2bb', shadow: '0 6px 12px rgba(243, 164, 27, 0.14)' },
    { accent: '#d85a5a', top: '#fdf0f0', bottom: '#fae6e6', border: '#f3d1d1', shadow: '0 6px 12px rgba(216, 90, 90, 0.14)' },
    { accent: '#17a2b8', top: '#ecfbfe', bottom: '#e1f6fa', border: '#c8ebf3', shadow: '0 6px 12px rgba(23, 162, 184, 0.14)' },
];

const applyTonePalette = (icon, palette) => {
    icon.style.setProperty('--icon-accent', palette.accent);
    icon.style.setProperty('--icon-bg-top', palette.top);
    icon.style.setProperty('--icon-bg-bottom', palette.bottom);
    icon.style.setProperty('--icon-border', palette.border);
    icon.style.setProperty('--icon-shadow', palette.shadow);
};

export const initProfileVisualSeeds = (root = document) => {
    const avatars = collectNodes(root, '.avatar[data-seed]');
    const logoPlaceholders = collectNodes(root, '.review-list-card__logo[data-seed], .pro-reviews-avatar[data-seed]');
    const sideAvatars = collectNodes(root, '.profile-side-list__avatar[data-seed]');
    const profileToneIcons = collectNodes(root, '.profile-quick-stats__icon[data-seed], .profile-education-item__icon[data-seed]');

    if (!avatars.length && !logoPlaceholders.length && !sideAvatars.length && !profileToneIcons.length) {
        return;
    }

    avatars.forEach((avatar) => {
        const seed = avatar.dataset.seed ?? '';
        const value = hashSeed(seed);
        const palette = avatarPalettes[value % avatarPalettes.length];
        const invert = ((value >> 8) & 1) === 1;

        avatar.classList.add('avatar--placeholder');
        avatar.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
        avatar.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
        avatar.style.setProperty('--av-icon', '#ffffff');
        avatar.style.setProperty('--av-ring', palette.ring);
        avatar.style.setProperty('--av-glow', palette.glow);
    });

    logoPlaceholders.forEach((logo) => {
        const image = logo.querySelector('img:not(.is-error)');
        if (image instanceof HTMLImageElement && (!image.complete || image.naturalWidth > 0)) {
            return;
        }

        const seed = logo.dataset.seed ?? '';
        const value = hashSeed(seed);
        const palette = avatarPalettes[value % avatarPalettes.length];
        const invert = ((value >> 8) & 1) === 1;

        logo.classList.add('has-random-gradient');
        logo.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
        logo.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
        logo.style.setProperty('--av-text', '#ffffff');
    });

    sideAvatars.forEach((avatar) => {
        const seed = avatar.dataset.seed ?? '';
        const value = hashSeed(seed);
        const palette = avatarPalettes[value % avatarPalettes.length];
        const invert = ((value >> 8) & 1) === 1;

        avatar.style.backgroundImage = `linear-gradient(135deg, ${invert ? palette.bg2 : palette.bg1} 0%, ${invert ? palette.bg1 : palette.bg2} 100%)`;
    });

    const groupedToneIcons = [];

    collectNodes(root, '.profile-quick-stats, .profile-education-list').forEach((group) => {
        const icons = Array.from(group.querySelectorAll('.profile-quick-stats__icon[data-seed], .profile-education-item__icon[data-seed]'));
        if (!icons.length) return;

        groupedToneIcons.push(...icons);

        const used = new Set();
        let previousIndex = -1;
        const maxUnique = tonePalettes.length;

        icons.forEach((icon) => {
            const seed = icon.dataset.seed ?? '';
            const value = hashSeed(seed);
            const baseIndex = value % tonePalettes.length;
            let nextIndex = baseIndex;
            const canForceUnique = icons.length <= maxUnique;

            if (canForceUnique) {
                while (used.has(nextIndex)) {
                    nextIndex = (nextIndex + 1) % tonePalettes.length;
                }
            } else if (nextIndex === previousIndex) {
                nextIndex = (nextIndex + 1) % tonePalettes.length;
            }

            if (nextIndex === previousIndex && tonePalettes.length > 1) {
                nextIndex = (nextIndex + 1) % tonePalettes.length;
            }

            used.add(nextIndex);
            previousIndex = nextIndex;
            applyTonePalette(icon, tonePalettes[nextIndex]);
        });
    });

    const groupedSet = new Set(groupedToneIcons);

    profileToneIcons.forEach((icon) => {
        if (groupedSet.has(icon)) return;

        const seed = icon.dataset.seed ?? '';
        const value = hashSeed(seed);
        applyTonePalette(icon, tonePalettes[value % tonePalettes.length]);
    });
};

export const handleSeededImageError = (image) => {
    if (!(image instanceof HTMLImageElement)) {
        return;
    }

    const shell = image.closest('[data-image-fallback-shell]');
    if (!(shell instanceof HTMLElement)) {
        image.classList.add('is-error');
        return;
    }

    if (!shell.dataset.seed) {
        const alt = image.getAttribute('alt')?.trim() ?? '';
        if (alt !== '') {
            shell.dataset.seed = alt;
        }
    }

    shell.classList.add('is-broken-image', 'has-random-gradient');
    image.classList.add('is-error');
    initProfileVisualSeeds(shell);
};

export const hydrateSeededImageFallbacks = (root = document) => {
    collectNodes(root, '[data-image-fallback-shell] img').forEach((image) => {
        if (!(image instanceof HTMLImageElement)) {
            return;
        }

        if (image.dataset.seededFallbackBound !== '1') {
            image.dataset.seededFallbackBound = '1';
            image.addEventListener('error', () => handleSeededImageError(image), { once: true });
        }

        if (image.complete && image.naturalWidth === 0) {
            handleSeededImageError(image);
        }
    });
};

initProfileVisualSeeds(document);
hydrateSeededImageFallbacks(document);

window.DoviraInitProfileVisualSeeds = initProfileVisualSeeds;
window.DoviraHandleSeededImageError = handleSeededImageError;
window.DoviraHydrateSeededImageFallbacks = hydrateSeededImageFallbacks;

(() => {
    const menu = document.querySelector('[data-user-menu]');
    if (!menu) return;

    const toggle = menu.querySelector('[data-user-menu-toggle]');
    const dropdown = menu.querySelector('[data-user-menu-dropdown]');
    if (!toggle || !dropdown) return;

    dropdown.hidden = false;
    dropdown.removeAttribute('hidden');

    const setOpen = (open) => {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu.classList.toggle('is-open', open);
        // Dim the page behind the dropdown on mobile (CSS gates the visual).
        document.body.classList.toggle('user-menu-open', open);
    };

    toggle.addEventListener('click', (event) => {
        event.preventDefault();
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
})();

let accountSidebarMenuController = null;

export const initAccountSidebarMenu = (root = document) => {
    if (!document.body.classList.contains('page-account')) return;

    const toggle = root.querySelector('[data-account-menu-toggle]') ?? document.querySelector('[data-account-menu-toggle]');
    const close = root.querySelector('[data-account-menu-close]') ?? document.querySelector('[data-account-menu-close]');
    const overlay = root.querySelector('[data-account-menu-overlay]') ?? document.querySelector('[data-account-menu-overlay]');
    const sidebar = root.querySelector('[data-account-sidebar]') ?? document.querySelector('[data-account-sidebar]');

    if (!toggle || !overlay || !sidebar) return;

    accountSidebarMenuController?.abort();
    accountSidebarMenuController = new AbortController();
    const { signal } = accountSidebarMenuController;
    const mq = window.matchMedia('(max-width: 960px)');

    const setOpen = (open) => {
        if (!mq.matches) {
            document.body.classList.remove('is-account-menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            sidebar.classList.remove('is-open');
            return;
        }

        if (open) {
            document.body.classList.remove('is-menu-open');
        }

        document.body.classList.toggle('is-account-menu-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        sidebar.classList.toggle('is-open', open);
    };

    toggle.addEventListener('click', () => setOpen(true), { signal });
    close?.addEventListener('click', () => setOpen(false), { signal });
    overlay.addEventListener('click', () => setOpen(false), { signal });

    sidebar.addEventListener('click', (event) => {
        if (event.target.closest('a, [data-account-tab-trigger]')) {
            setOpen(false);
        }
    }, { signal });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    }, { signal });

    mq.addEventListener('change', () => {
        if (!mq.matches) {
            setOpen(false);
        }
    }, { signal });
};

initAccountSidebarMenu(document);

/* ------------------------------------------------------------------ */
/* Клієнтське перемикання вкладок «Мій кабінет» — як у PRO-кабінеті:    */
/* панелі вже відрендерені сервером, ми лише показуємо потрібну без     */
/* перезавантаження, синхронізуючи URL (?tab=) та історію.             */
/* ------------------------------------------------------------------ */
let accountTabsController = null;

export const initAccountTabs = (root = document) => {
    if (!document.body.classList.contains('page-account')) return;

    const triggers = Array.from(root.querySelectorAll('[data-account-tab-trigger]'));
    const panels = Array.from(root.querySelectorAll('[data-account-tab-panel]'));
    // Тільки звичайний кабінет має ці тригери; PRO-кабінет має власну логіку.
    if (!triggers.length || !panels.length) return;

    const titleNodes = Array.from(root.querySelectorAll('[data-account-tab-title]'));
    const LABELS = {
        dashboard: 'Профіль',
        reviews: 'Мої відгуки',
        notifications: 'Сповіщення',
        saved: 'Обране',
        settings: 'Налаштування',
    };

    const normalize = (tab) => {
        if (tab === 'profile') return 'dashboard';
        return LABELS[tab] ? tab : 'dashboard';
    };

    const urlTab = () => normalize(new URL(window.location.href).searchParams.get('tab') || 'dashboard');

    const setTab = (rawTab, { push = true, scrollTop = true } = {}) => {
        const tab = normalize(rawTab);

        triggers.forEach((trigger) => {
            trigger.classList.toggle('is-active', trigger.dataset.accountTabTarget === tab);
        });
        panels.forEach((panel) => {
            panel.hidden = panel.dataset.accountTabPanel !== tab;
        });
        titleNodes.forEach((node) => {
            node.textContent = LABELS[tab];
        });

        if (push) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tab);
            // Пагінація відгуків більше не стосується нової вкладки.
            if (tab !== 'reviews') url.searchParams.delete('reviews_page');
            window.history.pushState({ accountTab: tab }, '', url.toString());
        }
        if (scrollTop) {
            window.scrollTo({ top: 0, behavior: 'auto' });
        }
    };

    accountTabsController?.abort();
    accountTabsController = new AbortController();
    const { signal } = accountTabsController;

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            setTab(trigger.dataset.accountTabTarget);
        }, { signal });
    });

    window.addEventListener('popstate', () => {
        setTab(urlTab(), { push: false, scrollTop: false });
    }, { signal });

    // Стартовий стан беремо з URL (сервер уже виставив is-active, але
    // синхронізуємо про всяк випадок — без запису в історію та без скролу).
    setTab(urlTab(), { push: false, scrollTop: false });
};

initAccountTabs(document);
