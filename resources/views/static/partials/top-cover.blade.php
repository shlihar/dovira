{{--
    Обкладинка картки рейтингу: аватарки учасників замість абстрактного
    градієнта. Градієнт лишається тлом і підкладкою, якщо фото немає.
    $avatars — до чотирьох елементів [name, url]; $icon — іконка категорії.
--}}
@if (! empty($avatars))
    <span class="top-cover top-cover--{{ min(count($avatars), 4) }}">
        @foreach ($avatars as $avatar)
            <span class="top-cover__item">
                <img src="{{ $avatar['url'] }}" alt="" loading="lazy" decoding="async">
            </span>
        @endforeach
    </span>
@else
    <i class="{{ $icon ?? 'fa-solid fa-ranking-star' }}"></i>
@endif
