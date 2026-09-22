<?php

namespace Tests\Unit;

use App\Support\ProfileSeo;
use Tests\TestCase;

class ProfileSeoTest extends TestCase
{
    public function test_search_title_starts_with_profile_name_and_reviews_keyword(): void
    {
        $title = ProfileSeo::resolvedSearchTitle(
            'Prikhodko & Partners, юридичні послуги - Dovira',
            'Prikhodko & Partners',
            'Адвокати',
            'Київ',
            true,
            4.8,
            123,
            true
        );

        $this->assertSame('Prikhodko & Partners — 4.8/5 · 123 відгуки, досьє | DOVIRA', $title);
    }

    public function test_short_name_dossier_title_gets_curiosity_hook(): void
    {
        $title = ProfileSeo::resolvedSearchTitle(null, 'Адвокат Петренко', 'Адвокати', 'Київ', true, 4.8, 37, true);

        $this->assertSame('Адвокат Петренко: відгуки 4.8/5 і досьє — що варто знати | DOVIRA', $title);
        $this->assertLessThanOrEqual(68, mb_strlen($title));
    }

    public function test_ai_summary_profile_title_gets_ai_marker(): void
    {
        $title = ProfileSeo::resolvedSearchTitle(null, 'Стоматологія SmileDent', 'Стоматологи', 'Одеса', true, 4.2, 67, false, true);

        $this->assertSame('Стоматологія SmileDent — відгуки 4.2/5: AI-розбір | DOVIRA', $title);
    }

    public function test_long_search_title_keeps_reviews_keyword(): void
    {
        $title = ProfileSeo::resolvedSearchTitle(
            null,
            'Дуже довга юридична компанія з назвою яка не влазить у сніпет Google',
            'Адвокати',
            'Київ',
            true,
            4.9,
            120,
            true
        );

        $this->assertLessThanOrEqual(68, mb_strlen($title));
        $this->assertStringContainsString('відгуки | DOVIRA', $title);
    }

    public function test_search_description_for_dossier_profile_is_hook_without_raw_dossier_text(): void
    {
        $description = ProfileSeo::resolvedSearchDescription(
            'Дякуємо професіоналам своєї справи, за якісну та чітку роботу.',
            'Приходько та партнери',
            'Адвокати',
            'Київ',
            [],
            null,
            'Після пʼяти спокійних років — хвиля скарг: 29 негативних відгуків за 2025–2026. Дорогі консультації без конкретики, процесуальні помилки. Фіксуйте виконавця і етапи в договорі.',
            true,
            3.1,
            44,
            true,
            'yellow',
            null
        );

        $this->assertStringStartsWith('⚠️ Приходько та партнери: є що перевірити. Суперечливі сигнали в досьє.', $description);
        $this->assertStringContainsString('Рейтинг 3.1/5 · 44 відгуки', $description);
        // Сирий текст досьє (accusatory) і випадковий сторонній seo_description
        // у сніпет потрапляти не мають.
        $this->assertStringNotContainsString('негативних відгуків', $description);
        $this->assertStringNotContainsString('Дякуємо професіоналам', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
    }

    public function test_red_verdict_gets_aggressive_opinion_hook_without_accusations(): void
    {
        $description = ProfileSeo::resolvedSearchDescription(
            null,
            'Адвокат Петренко',
            'Адвокати',
            'Київ',
            [],
            null,
            'Кілька відгуків прямо позначені «ОБЕРЕЖНО: ШАХРАЇ». Договір укладають з ФОП.',
            true,
            2.4,
            37,
            true,
            'red',
            'Кілька відгуків прямо позначені «ОБЕРЕЖНО: ШАХРАЇ».'
        );

        $this->assertStringStartsWith('🔴 Адвокат Петренко: у досьє — серйозні тривожні сигнали.', $description);
        $this->assertStringContainsString('Прочитайте, перш ніж звертатися.', $description);
        // Факт-звинувачення з сирого досьє/verdict_note у сніпет не виносимо.
        $this->assertStringNotContainsString('ШАХРАЇ', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
    }

    public function test_ai_review_summary_snippet_lists_praise_and_complaints(): void
    {
        $description = ProfileSeo::resolvedSearchDescription(
            null,
            'Стоматологія SmileDent',
            'Стоматологи',
            'Одеса',
            [],
            null,
            null,
            true,
            4.2,
            67,
            false,
            null,
            null,
            [
                'summary' => 'Клієнти загалом задоволені.',
                'aspects' => [
                    ['topic' => 'Професійність', 'sentiment' => 'positive', 'note' => 'x'],
                    ['topic' => 'Ціни', 'sentiment' => 'negative', 'note' => 'x'],
                ],
                'tags' => [],
            ]
        );

        $this->assertStringStartsWith('AI розібрав відгуки про Стоматологія SmileDent: що хвалять і на що скаржаться.', $description);
        $this->assertStringContainsString('Хвалять: професійність; скарги: ціни.', $description);
        $this->assertLessThanOrEqual(160, mb_strlen($description));
    }

    public function test_search_description_for_dossier_profile_without_verdict_uses_curiosity_hook(): void
    {
        $description = ProfileSeo::resolvedSearchDescription(
            null,
            'ЮК «Правовий захист»',
            'Юристи',
            'Київ',
            [],
            null,
            null,
            true,
            4.6,
            89,
            true,
            null,
            null
        );

        $this->assertSame(
            'Що варто знати про ЮК «Правовий захист»? Досьє: факти, джерела, репутація. '
            . 'Рейтинг 4.6/5 · 89 відгуків реальних клієнтів. Перевірте перед зверненням на DOVIRA.',
            $description
        );
    }
}
