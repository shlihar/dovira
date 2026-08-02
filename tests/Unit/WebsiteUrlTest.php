<?php

namespace Tests\Unit;

use App\Support\WebsiteUrl;
use Tests\TestCase;

class WebsiteUrlTest extends TestCase
{
    public function test_href_returns_null_for_placeholder_hosts(): void
    {
        $this->assertNull(WebsiteUrl::href('https://example.com'));
        $this->assertNull(WebsiteUrl::href('example.org'));
        $this->assertNull(WebsiteUrl::href('https://dovira.org'));
        $this->assertNull(WebsiteUrl::href('localhost'));
    }

    public function test_display_returns_null_for_placeholder_hosts(): void
    {
        $this->assertNull(WebsiteUrl::display('https://example.com'));
        $this->assertNull(WebsiteUrl::display('https://www.example.net/path'));
        $this->assertNull(WebsiteUrl::display('dovira.org'));
    }

    public function test_display_keeps_real_domain(): void
    {
        $this->assertSame('law.vn.ua', WebsiteUrl::display('https://law.vn.ua'));
        $this->assertSame('malyk.com.ua/about', WebsiteUrl::display('https://www.malyk.com.ua/about/'));
    }
}
