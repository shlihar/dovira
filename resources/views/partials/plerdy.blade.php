{{-- Plerdy: кліки, теплові карти й записи сесій. Підключається лише коли в
     оточенні є PLERDY_SITE_HASH — локально й на тестах змінної немає, тож
     скрипт не вантажиться. Перевірку APP_ENV навмисно НЕ робимо: бойовий
     сервер працює з APP_ENV=local (та сама історія, що з Clarity). --}}
@if (filled(config('services.plerdy.site_hash')))
    <script data-plerdy_code="1">
        (function (w, d) {
            if (w.__plerdyCode) return;
            w.__plerdyCode = 1;
            w._protocol = w.location.protocol === 'https:' ? 'https://' : 'http://';
            w._site_hash_code = @json((string) config('services.plerdy.site_hash'));
            w._suid = {{ (int) config('services.plerdy.suid') }};
            var s = d.createElement('script');
            s.async = true;
            s.referrerPolicy = 'strict-origin-when-cross-origin';
            s.src = 'https://a.plerdy.com/public/js/click/main.js?v=' + Math.random();
            d.head.appendChild(s);
        })(window, document);
    </script>
@endif
