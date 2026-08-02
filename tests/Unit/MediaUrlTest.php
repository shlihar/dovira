<?php

namespace Tests\Unit;

use App\Models\Profile;
use App\Support\MediaUrl;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUrlTest extends TestCase
{
    public function test_public_image_url_keeps_real_external_avatar_urls(): void
    {
        $url = 'https://top20.ua/media-resize-url/example.jpeg?expires=2098459644&signature=abc';

        $this->assertSame($url, MediaUrl::publicImageUrl($url, [
            'img/empty.png',
            'storage/img/empty.png',
        ]));
    }

    public function test_public_image_url_hides_known_placeholder_paths(): void
    {
        $this->assertNull(MediaUrl::publicImageUrl('img/empty.png?5cae68064', [
            'img/empty.png',
            'storage/img/empty.png',
        ]));
    }

    public function test_public_image_url_hides_missing_local_files(): void
    {
        $this->assertNull(MediaUrl::publicImageUrl('catalog-media/profiles/missing-logo.jpg'));
    }

    public function test_public_image_url_resolves_existing_local_files(): void
    {
        Storage::disk('public')->put('catalog-media/profiles/existing-logo.jpg', 'demo');

        $this->assertSame(
            '/media/catalog-media/profiles/existing-logo.jpg',
            MediaUrl::publicImageUrl('catalog-media/profiles/existing-logo.jpg')
        );
    }

    public function test_thumb_url_is_relative_and_not_tied_to_app_url(): void
    {
        config(['app.url' => 'http://localhost']);

        Storage::disk('public')->put(
            'catalog-media/profiles/thumb-logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lV6NqAAAAABJRU5ErkJggg==')
        );

        $url = MediaUrl::thumbUrl('catalog-media/profiles/thumb-logo.png', 160);

        $this->assertIsString($url);
        $this->assertStringStartsWith('/media/', $url);
        $this->assertStringNotContainsString('localhost', $url);
    }

    public function test_thumb_url_rebuilds_existing_thumb_urls_for_requested_width(): void
    {
        Storage::disk('public')->put(
            'catalog-media/profiles/source-logo.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lV6NqAAAAABJRU5ErkJggg==')
        );
        $cache = public_path('media/thumb/320/catalog-media/profiles/source-logo.png.webp');
        @mkdir(dirname($cache), 0777, true);
        file_put_contents($cache, 'cached-webp');

        try {
            $url = MediaUrl::thumbUrl('/media/thumb/160/catalog-media/profiles/source-logo.png.webp', 320);

            $this->assertSame('/media/thumb/320/catalog-media/profiles/source-logo.png.webp', $url);
        } finally {
            @unlink($cache);
        }
    }

    public function test_public_image_url_normalizes_local_absolute_media_urls(): void
    {
        config(['app.url' => 'https://mydovira.com']);
        Storage::disk('public')->put('catalog-media/profiles/logo.png', 'demo');

        $this->assertSame(
            '/media/catalog-media/profiles/logo.png',
            MediaUrl::publicImageUrl('http://127.0.0.1:8899/media/catalog-media/profiles/logo.png')
        );
    }

    public function test_profile_logo_url_generates_stable_fallback_image(): void
    {
        $profile = new Profile([
            'name' => 'Fallback Company',
            'slug' => 'fallback-company',
        ]);

        $url = MediaUrl::profileLogoUrl($profile, 160);

        $this->assertIsString($url);
        $this->assertStringStartsWith('/media/catalog-media/profile-fallbacks/', $url);
        $this->assertStringEndsWith('.svg', $url);
    }

    public function test_avatar_url_generates_stable_fallback_image(): void
    {
        $url = MediaUrl::avatarUrl(null, 'Fallback User', 96);

        $this->assertIsString($url);
        $this->assertStringStartsWith('/media/catalog-media/avatar-fallbacks/', $url);
        $this->assertStringEndsWith('.svg', $url);
    }

    public function test_public_image_url_resolves_legacy_public_files(): void
    {
        $path = public_path('catalog-media/media-url-legacy.jpg');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'legacy');

        try {
            $this->assertSame(
                '/media/catalog-media/media-url-legacy.jpg',
                MediaUrl::publicImageUrl('catalog-media/media-url-legacy.jpg')
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_public_image_url_hides_known_top20_user_icon_placeholders(): void
    {
        $this->assertNull(MediaUrl::publicImageUrl('img/avatars/icons_uzer_v5.png?17ccf019c'));
    }
}
