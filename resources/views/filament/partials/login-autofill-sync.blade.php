<script>
    (() => {
        const isLoginPage = () => window.location.pathname.replace(/\/+$/, '') === '/admin/login';
        if (!isLoginPage()) return;

        const fields = [
            { selector: '#form\\.email', path: 'data.email' },
            { selector: '#form\\.password', path: 'data.password' },
            { selector: '#form\\.remember', path: 'data.remember', boolean: true },
        ];

        const findComponent = (element) => {
            const root = element?.closest('[wire\\:id]');
            const id = root?.getAttribute('wire:id');
            if (!id || !window.Livewire?.find) return null;

            try {
                return window.Livewire.find(id);
            } catch (error) {
                return null;
            }
        };

        const syncField = (field) => {
            const input = document.querySelector(field.selector);
            if (!input) return;

            const component = findComponent(input);
            if (!component?.set) return;

            const value = field.boolean ? Boolean(input.checked) : input.value;
            component.set(field.path, value);
        };

        const syncAll = () => fields.forEach(syncField);

        window.addEventListener('pageshow', () => window.setTimeout(syncAll, 80));
        window.addEventListener('load', () => window.setTimeout(syncAll, 160));
        document.addEventListener('input', (event) => {
            if (event.target?.matches?.('#form\\.email, #form\\.password, #form\\.remember')) {
                window.setTimeout(syncAll, 0);
            }
        }, true);
        document.addEventListener('change', (event) => {
            if (event.target?.matches?.('#form\\.email, #form\\.password, #form\\.remember')) {
                window.setTimeout(syncAll, 0);
            }
        }, true);
        document.addEventListener('submit', (event) => {
            if (event.target?.matches?.('form[wire\\:submit="authenticate"]')) {
                syncAll();
            }
        }, true);
        document.addEventListener('click', (event) => {
            if (event.target?.closest?.('form[wire\\:submit="authenticate"] button[type="submit"]')) {
                syncAll();
            }
        }, true);
    })();
</script>
