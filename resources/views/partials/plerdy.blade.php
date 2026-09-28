{{-- Plerdy: кліки, теплові карти й записи сесій. Код лишаємо ТОЧНО таким, як
     його видає сам Plerdy: їхня перевірка встановлення шукає цей рядок у
     вихідному HTML, і будь-яке переформатування (пробіли навколо «=», інші
     лапки) вона не впізнає. Підключається лише за наявності PLERDY_SITE_HASH —
     локально й на тестах змінної немає. Перевірку APP_ENV не робимо: бойовий
     сервер працює з APP_ENV=local. --}}
@if (filled(config('services.plerdy.site_hash')))
<!-- BEGIN PLERDY CODE -->
<script data-plerdy_code='1'>
(function(w,d){
  if(w.__plerdyCode)return;
  w.__plerdyCode=1;
  w._protocol=w.location.protocol=="https:"?"https://":"http://";
  w._site_hash_code="{{ config('services.plerdy.site_hash') }}";
  w._suid={{ (int) config('services.plerdy.suid') }};
  var s=d.createElement("script");
  s.async=true;
  s.referrerPolicy="strict-origin-when-cross-origin";
  s.src="https://a.plerdy.com/public/js/click/main.js?v="+Math.random();
  d.head.appendChild(s);
})(window,document);
</script>
<!-- END PLERDY CODE -->
@endif
