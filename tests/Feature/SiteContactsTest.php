<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    public function test_public_pages_show_configured_support_contacts(): void
    {
        config()->set('site_contacts.email', 'info@mydovira.com');
        config()->set('site_contacts.telegram_username', 'dovira_support');
        config()->set('site_contacts.phone', '+380937269578');

        $this->get('/')
            ->assertOk()
            ->assertSee('info@mydovira.com')
            ->assertSee('+380937269578')
            ->assertSee('https://t.me/dovira_support', false);

        $this->get('/platform')
            ->assertOk()
            ->assertSee('id="contacts"', false)
            ->assertSee('@dovira_support')
            ->assertSee('mailto:info@mydovira.com', false)
            ->assertSee('tel:+380937269578', false);

        $this->get('/faq')
            ->assertOk()
            ->assertSee('https://t.me/dovira_support', false);

        $this->get('/privacy')
            ->assertOk()
            ->assertSee('info@mydovira.com')
            ->assertSee('@dovira_support')
            ->assertSee('+380937269578');

        $this->get('/terms')
            ->assertOk()
            ->assertSee('info@mydovira.com')
            ->assertSee('@dovira_support')
            ->assertSee('+380937269578');
    }
}
