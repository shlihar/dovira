{{--
    Обкладинка картки рейтингу. Пріоритет — тематичне фото категорії
    (public/static/img/top-covers/{slug}.jpg, див. config/top_rating_covers.php).
    Поки фото немає, лишається градієнт зі значком категорії, як у блозі.
--}}
@if (! empty($coverUrl))
    <img class="top-cover__photo" src="{{ $coverUrl }}" alt="" loading="lazy" decoding="async">
@else
    <i class="{{ $icon ?? 'fa-solid fa-ranking-star' }}"></i>
@endif
