(() => {
    const burger = document.querySelector('[data-burger]');
    const mobile = document.querySelector('[data-mobile]');
    const overlay = document.querySelector('[data-overlay]');
    if (!burger || !mobile || !overlay) return;
    const closeBtn = mobile.querySelector('[data-close]');

    const ANIM_MS = 220;
    let closeTimer = null;

    const setOpen = (open) => {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }

        burger.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            mobile.hidden = false;
            overlay.hidden = false;
            // Allow layout to apply before starting transition.
            requestAnimationFrame(() => {
                document.documentElement.classList.add('is-menu-open');
            });
            return;
        }

        document.documentElement.classList.remove('is-menu-open');
        closeTimer = window.setTimeout(() => {
            mobile.hidden = true;
            overlay.hidden = true;
        }, ANIM_MS);
    };

    burger.addEventListener('click', () => {
        const open = burger.getAttribute('aria-expanded') !== 'true';
        setOpen(open);
    });

    mobile.addEventListener('click', (e) => {
        const link = e.target.closest('a');
        if (link) setOpen(false);
    });

    overlay.addEventListener('click', () => setOpen(false));
    closeBtn?.addEventListener('click', () => setOpen(false));

    window.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });
})();

(() => {
    const avatars = document.querySelectorAll('.avatar[data-seed]');
    if (!avatars.length) return;

    const hash = (input) => {
        let value = 0;
        for (let i = 0; i < input.length; i += 1) {
            value = (value << 5) - value + input.charCodeAt(i);
            value |= 0;
        }
        return Math.abs(value);
    };

    const palettes = [
        { bg1: '#eaf2ff', bg2: '#1f68ff', ring: 'rgba(31, 104, 255, 0.22)', glow: 'rgba(31, 104, 255, 0.26)' },
        { bg1: '#e8f5ff', bg2: '#0f4fb8', ring: 'rgba(15, 79, 184, 0.22)', glow: 'rgba(15, 79, 184, 0.26)' },
        { bg1: '#edf0ff', bg2: '#3b5bff', ring: 'rgba(59, 91, 255, 0.20)', glow: 'rgba(59, 91, 255, 0.24)' },
        { bg1: '#eefcff', bg2: '#1483ff', ring: 'rgba(20, 131, 255, 0.18)', glow: 'rgba(20, 131, 255, 0.22)' },
        { bg1: '#e8fffb', bg2: '#0d86c6', ring: 'rgba(13, 134, 198, 0.18)', glow: 'rgba(13, 134, 198, 0.22)' },
        { bg1: '#f1f0ff', bg2: '#5a4bff', ring: 'rgba(90, 75, 255, 0.18)', glow: 'rgba(90, 75, 255, 0.22)' },
        { bg1: '#eaf6ff', bg2: '#0b63d6', ring: 'rgba(11, 99, 214, 0.20)', glow: 'rgba(11, 99, 214, 0.24)' },
        { bg1: '#eff7ff', bg2: '#1b86ff', ring: 'rgba(27, 134, 255, 0.18)', glow: 'rgba(27, 134, 255, 0.22)' },
        { bg1: '#e9f3ff', bg2: '#2753d9', ring: 'rgba(39, 83, 217, 0.20)', glow: 'rgba(39, 83, 217, 0.24)' },
        { bg1: '#e8f0ff', bg2: '#3a66ff', ring: 'rgba(58, 102, 255, 0.18)', glow: 'rgba(58, 102, 255, 0.22)' },
    ];

    avatars.forEach((avatar) => {
        const seed = avatar.dataset.seed ?? '';
        avatar.classList.add('avatar--placeholder');
        const value = hash(seed);
        const palette = palettes[value % palettes.length];
        const invert = ((value >> 8) & 1) === 1;

        avatar.style.setProperty('--av-bg-1', invert ? palette.bg2 : palette.bg1);
        avatar.style.setProperty('--av-bg-2', invert ? palette.bg1 : palette.bg2);
        avatar.style.setProperty('--av-icon', '#ffffff');
        avatar.style.setProperty('--av-ring', palette.ring);
        avatar.style.setProperty('--av-glow', palette.glow);
    });
})();
