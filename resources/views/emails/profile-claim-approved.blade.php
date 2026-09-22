<x-mail::message>
# Профіль підтверджено

Ми підтвердили права на профіль **{{ $profileName }}** на DOVIRA.

Профіль уже публічний: його бачать клієнти на DOVIRA та можуть знаходити в Google за назвою.

Підтвердження привʼязало сторінку до вашого акаунта — аналітика переглядів уже відкрита у вашому кабінеті. Щоб редагувати інформацію, відповідати на відгуки, працювати з досьє та отримувати контакти клієнтів із заявок, оформіть підписку.

{{ \App\Support\ProPricing::launchCohortPriceLine() }} {{ \App\Support\ProPricing::launchCohortLockLine() }} {{ \App\Support\ProPricing::launchCohortCounterLine() }}

Якщо розмістите бейдж DOVIRA на своєму сайті або в соцмережах, отримаєте знижку 20%.

<x-mail::button :url="$actionUrl">
Оформити підписку
</x-mail::button>

Дякуємо,<br>
Команда DOVIRA
</x-mail::message>
