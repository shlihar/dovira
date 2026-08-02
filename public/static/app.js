import './js/modules/core-ui.js';
// ?v=2: + події воронки профілю (info_tab_view / dossier_open / dossier_view)
import './js/modules/analytics.js?v=2';
// ?v=2: трекінг A/B hero (hero_view / hero_cta)
import './js/modules/track-actions.js?v=2';
import './js/modules/profile-ui.js';
// ?v=2: вимкнено автопрокрутку каруселей
import './js/modules/carousels.js?v=2';
// pro.js (~140 KB) потрібен лише на сторінці тарифів /pro та в кабінеті
// /pro/account — вантажимо його динамічно, а не на кожній публічній сторінці.
if (document.body.classList.contains('page-pro') || document.body.classList.contains('page-pro-account')) {
    import('./js/modules/pro.js?v=12');
}
// ?v=3: business-cta делегує пошук у повноекранний мобільний модал (scope/action форми).
import './js/modules/search.js?v=3';
import './js/modules/content-ui.js?v=3';
// ?v=3: ЧПУ-URL категорії у каталозі без перезавантаження + синк шапки (H1/крихти).
import './js/modules/catalog.js?v=3';
import './js/modules/catalog-categories.js';
// ?v=2: вимкнено reveal-ефект появи секцій
import './js/modules/animations.js?v=2';
import './js/modules/review-popup.js?v=2';
import './js/modules/phone-mask.js';
import './js/modules/claim-wizard.js?v=3';
import './js/modules/claim-otp.js?v=3';
// ?v=2: біндимо всі екземпляри форми заявки, не лише першу.
import './js/modules/lead-form.js?v=2';
import './js/modules/lead-popup.js';
import './js/modules/favorites.js';
import './js/modules/card-tags-tooltip.js';
// ?v=3: браузер кешує модуль за URL без версії, а він суттєво змінювався
// (селектор міста, підміна топів і відгуків) — бампаємо вручну при змінах.
import './js/modules/home-city.js?v=7';
// Секція «Категорії відгуків»: стрічка активності + «Показати ще» + мобільний перемикач.
import './js/modules/category-ticker.js?v=3';
