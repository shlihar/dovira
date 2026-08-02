{{-- Селектор міста в hero-пошуку: лейбл оновлює home-city.js (гео з IP/кукі
     або явний вибір). Кнопки не сабмітять форму — місто летить у каталог
     через прихований input[name="regions[]"] поруч у формі. --}}
<div class="home-city-picker {{ $pickerClass ?? '' }}" data-city-picker>
    <button
        type="button"
        class="home-city-picker__toggle"
        data-city-toggle
        aria-haspopup="listbox"
        aria-expanded="false"
        title="Місто пошуку"
    >
        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
        <span data-city-label>Вся Україна</span>
        <i class="fa-solid fa-chevron-down home-city-picker__chevron" aria-hidden="true"></i>
    </button>
    <div class="home-city-picker__menu" data-city-menu hidden>
        <button type="button" class="home-city-picker__option home-city-picker__option--all is-active" data-city-option="">
            <i class="fa-solid fa-earth-europe" aria-hidden="true"></i>
            Вся Україна
        </button>
        <div class="home-city-picker__grid">
            @foreach ($cityOptions as $cityName)
                <button type="button" class="home-city-picker__option" data-city-option="{{ $cityName }}">{{ $cityName }}</button>
            @endforeach
        </div>
    </div>
</div>
