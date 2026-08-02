<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryService;
use App\Models\Profile;
use App\Models\ProfileDataSource;
use App\Models\ProfileReview;
use App\Models\Region;
use App\Models\Top20ImportBatch;
use App\Models\Top20ImportItem;
use App\Support\CategoryHierarchy;
use App\Support\GeneratedProfileLogo;
use App\Support\MediaUrl;
use App\Support\RegionCityDirectory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Top20BulkImportService
{
    /**
     * @var array<string, string|null>
     */
    private array $storedMediaCache = [];

    /**
     * @return array{
     *   status:string,
     *   changed:bool,
     *   profile_id:int,
     *   profile_name:string,
     *   previous_logo_url:?string,
     *   logo_url:?string,
     *   resolved_url:?string,
     *   source:?string,
     *   top20_url:?string,
     *   checked_candidates:int
     * }
     */
    public function repairProfileLogo(Profile $profile, bool $persist = true, bool $fetchTop20 = true, bool $preferTop20 = false): array
    {
        $profile->loadMissing('dataSources');

        $profileId = (int) $profile->id;
        $profileName = trim((string) ($profile->name ?? 'profile'));
        $previousLogoUrl = $this->nullIfBlank((string) ($profile->logo_url ?? ''));
        $currentResolvedUrl = MediaUrl::publicImageUrl($previousLogoUrl);

        if (! $preferTop20 && $currentResolvedUrl !== null) {
            return [
                'status' => 'already_ok',
                'changed' => false,
                'profile_id' => $profileId,
                'profile_name' => $profileName,
                'previous_logo_url' => $previousLogoUrl,
                'logo_url' => $previousLogoUrl,
                'resolved_url' => $currentResolvedUrl,
                'source' => 'current',
                'top20_url' => $this->resolveProfileTop20Url($profile),
                'checked_candidates' => 0,
            ];
        }

        if ($persist && filled($previousLogoUrl)) {
            $mediaResult = $this->localizePersistedProfileMedia($profile, false);
            if (($mediaResult['profile_updated'] ?? false) === true) {
                $profile->refresh();
                $resolvedAfterLocalization = MediaUrl::publicImageUrl($profile->logo_url);
                if ($resolvedAfterLocalization !== null) {
                    return [
                        'status' => 'repaired',
                        'changed' => true,
                        'profile_id' => $profileId,
                        'profile_name' => $profileName,
                        'previous_logo_url' => $previousLogoUrl,
                        'logo_url' => $this->nullIfBlank((string) ($profile->logo_url ?? '')),
                        'resolved_url' => $resolvedAfterLocalization,
                        'source' => 'localized_current',
                        'top20_url' => $this->resolveProfileTop20Url($profile),
                        'checked_candidates' => 1,
                    ];
                }
            }
        }

        $top20Url = $this->resolveProfileTop20Url($profile);
        $checkedCandidates = 0;

        foreach ($this->collectProfileLogoCandidates($profile, $fetchTop20, $top20Url, $preferTop20) as $candidate) {
            $candidateUrl = trim((string) ($candidate['url'] ?? ''));
            if ($candidateUrl === '') {
                continue;
            }

            $checkedCandidates++;
            $localized = $this->localizeImportedImageReference($candidateUrl, 'profiles', $profileName);
            if (! filled($localized)) {
                continue;
            }

            $resolvedUrl = MediaUrl::publicImageUrl($localized);
            if ($resolvedUrl === null) {
                continue;
            }

            $nextLogoUrl = $this->nullIfBlank((string) $localized);
            $changed = $nextLogoUrl !== $previousLogoUrl;

            if ($persist && $changed) {
                $profile->logo_url = $nextLogoUrl;

                if ($this->normalizeStoredMediaPath((string) ($profile->og_image_url ?? '')) === null
                    && $this->normalizeStoredMediaPath((string) $nextLogoUrl) !== null) {
                    $profile->og_image_url = $nextLogoUrl;
                }

                $profile->saveQuietly();
            }

            return [
                'status' => $changed ? 'repaired' : ($currentResolvedUrl !== null ? 'already_ok' : 'resolved'),
                'changed' => $changed,
                'profile_id' => $profileId,
                'profile_name' => $profileName,
                'previous_logo_url' => $previousLogoUrl,
                'logo_url' => $nextLogoUrl,
                'resolved_url' => $resolvedUrl,
                'source' => (string) ($candidate['source'] ?? 'candidate'),
                'top20_url' => $top20Url,
                'checked_candidates' => $checkedCandidates,
            ];
        }

        return [
            'status' => 'missing',
            'changed' => false,
            'profile_id' => $profileId,
            'profile_name' => $profileName,
            'previous_logo_url' => $previousLogoUrl,
            'logo_url' => $previousLogoUrl,
            'resolved_url' => null,
            'source' => null,
            'top20_url' => $top20Url,
            'checked_candidates' => $checkedCandidates,
        ];
    }

    /**
     * @return array{profile_updated:bool,reviews_updated:int}
     */
    public function localizePersistedProfileMedia(Profile $profile, bool $includeReviewAvatars = true): array
    {
        $profileUpdated = false;
        $reviewsUpdated = 0;
        $profileName = trim((string) ($profile->name ?? 'profile'));

        foreach (['logo_url', 'banner_url', 'og_image_url'] as $field) {
            $current = trim((string) ($profile->{$field} ?? ''));
            if ($current === '') {
                continue;
            }

            $localized = $this->localizeImportedImageReference($current, 'profiles', $profileName);
            if ($localized !== null && $localized !== $current) {
                $profile->{$field} = $localized;
                $profileUpdated = true;
            }
        }

        $gallery = collect((array) ($profile->gallery ?? []))
            ->map(fn ($item) => $this->localizeImportedImageReference((string) $item, 'gallery', $profileName))
            ->filter(fn ($item) => filled($item))
            ->unique()
            ->values()
            ->all();

        if ($gallery !== array_values((array) ($profile->gallery ?? []))) {
            $profile->gallery = $gallery;
            $profileUpdated = true;
        }

        if ($profileUpdated) {
            $profile->saveQuietly();
        }

        if ($this->normalizeStoredMediaPath((string) ($profile->og_image_url ?? '')) === null) {
            $fallbackOgImage = $this->normalizeStoredMediaPath((string) ($profile->banner_url ?? ''))
                ?? $this->normalizeStoredMediaPath((string) ($profile->logo_url ?? ''));

            if ($fallbackOgImage !== null && $fallbackOgImage !== $profile->og_image_url) {
                $profile->og_image_url = $fallbackOgImage;
                $profile->saveQuietly();
                $profileUpdated = true;
            }
        }

        if ($includeReviewAvatars) {
            ProfileReview::query()
                ->where('profile_id', $profile->id)
                ->whereNotNull('external_review_author_avatar_url')
                ->orderBy('id')
                ->chunkById(100, function ($reviews) use ($profileName, &$reviewsUpdated): void {
                    foreach ($reviews as $review) {
                        $current = trim((string) ($review->external_review_author_avatar_url ?? ''));
                        if ($current === '') {
                            continue;
                        }

                        $localized = $this->localizeImportedImageReference(
                            $current,
                            'review-avatars',
                            trim((string) ($review->external_review_author ?? '')) ?: $profileName,
                            (int) config('top20_bulk_import.review_avatar_max_bytes', 2 * 1024 * 1024)
                        );

                        if ($localized === null) {
                            if ($this->isTop20HostedMediaUrl($current)) {
                                $review->external_review_author_avatar_url = null;
                                $review->saveQuietly();
                                $reviewsUpdated++;
                            }

                            continue;
                        }

                        if ($localized === $current) {
                            continue;
                        }

                        $review->external_review_author_avatar_url = $localized;
                        $review->saveQuietly();
                        $reviewsUpdated++;
                    }
                });
        }

        return [
            'profile_updated' => $profileUpdated,
            'reviews_updated' => $reviewsUpdated,
        ];
    }

    public function refreshProfileFromTop20(Profile $profile, ?int $requestedByUserId = null, bool $publishProfile = false): array
    {
        $top20Url = $this->resolveProfileTop20Url($profile);
        if ($top20Url === null) {
            throw new \RuntimeException('Для цього профілю не знайдено Top20-джерело.');
        }

        $categoryContext = $this->resolveProfileImportCategoryContext($profile);
        if (! $categoryContext['category'] instanceof Category) {
            throw new \RuntimeException('Для цього профілю не визначено категорію імпорту.');
        }

        $profile->loadMissing(['region', 'categories']);

        $card = [
            'url' => $top20Url,
            'name' => (string) $profile->name,
            'city' => (string) ($profile->city ?: optional($profile->region)->name ?: ''),
            'address' => (string) ($profile->address ?? ''),
            'rating' => $profile->rating_avg,
            'reviews_count' => $profile->reviews_count,
        ];

        $details = $this->fetchCompanyDetails($top20Url);
        if (($details['name'] ?? '') === '' && blank($profile->name)) {
            throw new \RuntimeException('Top20 не повернув дані профілю для оновлення.');
        }
        $details = $this->localizeImportedDetailMedia($details);

        return $this->runTransactionWithRetry(function () use ($profile, $card, $details, $categoryContext, $requestedByUserId, $top20Url, $publishProfile): array {
            $resolvedCity = $this->canonicalImportedCity((string) ($details['city'] ?? $profile->city ?: ''));
            $resolvedRegionId = $this->resolveImportRegionId(
                (string) (optional($profile->region)->name ?? ''),
                $resolvedCity,
                $profile->region_id ? (int) $profile->region_id : null
            );

            $payload = $this->buildProfilePayload(
                $card,
                $details,
                $resolvedCity,
                $resolvedRegionId
            );

            $this->fillProfileFromImport($profile, $payload, true);
            $this->syncImportedTop20Content($profile, $details);

            if ($requestedByUserId) {
                $profile->updated_by_user_id = $requestedByUserId;
            }

            if ($publishProfile) {
                $profile->status = 'active';
                $profile->is_published = true;
                $profile->show_in_catalog = true;
            }

            $profile->save();
            $this->ensureProfileLogoFallback($profile);

            $this->syncImportedProfileCategories(
                $profile,
                (int) $categoryContext['category']->id,
                $categoryContext['subcategory_id'],
                false
            );
            $this->syncImportedDirections($profile, $categoryContext['category'], $details);
            $this->syncTop20ProfileDataSource($profile, $top20Url, $card, $details);

            $reviewStats = $this->syncExternalReviews($profile, $details);
            $this->normalizeImportedReviewSources($profile);
            $reviewStats['deleted'] = $this->pruneImportedExternalReviews($profile, (int) config('top20_bulk_import.max_reviews_per_profile', 100));
            $hiddenAiDrafts = $this->hideConflictingPublishedAiDrafts($profile);
            app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $profile->id);

            return [
                'profile_id' => $profile->id,
                'profile_created' => 0,
                'profile_updated' => 1,
                'reviews_created' => $reviewStats['created'],
                'reviews_updated' => $reviewStats['updated'],
                'reviews_deleted' => $reviewStats['deleted'],
                'reviews_hidden' => $hiddenAiDrafts,
                'top20_url' => $top20Url,
            ];
        });
    }

    /**
     * Імпортує лише відгуки з top20-картки в існуючий профіль. На відміну від
     * refreshProfileFromTop20 не перезаписує поля профілю (ПІБ, контакти тощо).
     *
     * @return array{created:int, updated:int, deleted:int}
     */
    public function importReviewsFromTop20Url(Profile $profile, string $top20Url): array
    {
        $top20Url = $this->normalizeTop20ProfileUrl($top20Url);
        if ($top20Url === '') {
            return ['created' => 0, 'updated' => 0, 'deleted' => 0];
        }

        $details = $this->fetchCompanyDetails($top20Url);
        $reviewStats = $this->syncExternalReviews($profile, $details);
        $this->normalizeImportedReviewSources($profile);
        $reviewStats['deleted'] = $this->pruneImportedExternalReviews($profile, (int) config('top20_bulk_import.max_reviews_per_profile', 100));
        $this->syncTop20ProfileDataSource($profile, $top20Url, [], $details);
        app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $profile->id);

        return $reviewStats;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function importCategory(string $listingUrl, Category $category, array $options = []): array
    {
        /** @var Top20ImportBatch|null $importBatch */
        $importBatch = $options['import_batch'] ?? null;
        $stats = [
            'listing_url' => $listingUrl,
            'pages_visited' => 0,
            'cards_found' => 0,
            'profiles_considered' => 0,
            'profiles_created' => 0,
            'profiles_updated' => 0,
            'profiles_skipped' => 0,
            'reviews_created' => 0,
            'reviews_updated' => 0,
            'reviews_hidden' => 0,
            'errors' => [],
        ];

        $cards = $this->collectListingCards($listingUrl, [
            'start_page' => $options['start_page'] ?? null,
            'end_page' => $options['end_page'] ?? null,
            'max_pages' => $options['max_pages'] ?? null,
            'max_profiles' => $options['max_profiles'] ?? null,
            'min_reviews' => $options['min_reviews'] ?? 1,
            'city_name' => $options['city_name'] ?? null,
        ]);

        $stats['pages_visited'] = (int) ($cards['_meta']['pages_visited'] ?? 0);
        $stats['cards_found'] = count($cards['items'] ?? []);

        $this->primeImportBatchProgress($importBatch, (array) ($cards['items'] ?? []), $category, $options, $stats);

        $cardsToProcess = array_values(array_filter(
            (array) ($cards['items'] ?? []),
            fn ($card): bool => is_array($card)
        ));
        $maxProfiles = ($options['max_profiles'] ?? null) !== null ? (int) $options['max_profiles'] : null;
        if ($maxProfiles !== null) {
            $cardsToProcess = array_slice($cardsToProcess, 0, max(0, $maxProfiles));
        }

        $concurrency = $this->profileFetchConcurrency();
        foreach (array_chunk($cardsToProcess, $concurrency) as $chunkOffset => $chunk) {
            $prefetchedHtml = $concurrency > 1 ? $this->fetchCompanyHtmlBatch($chunk) : [];

            foreach ($chunk as $chunkIndex => $card) {
                $globalIndex = ($chunkOffset * $concurrency) + $chunkIndex;
                $stats['profiles_considered']++;
                $this->markImportItemProcessing($importBatch, $card, $category, $options, $globalIndex + 1);

                try {
                    $top20Url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
                    $result = $this->importCompanyCard($card, $category, $options, $prefetchedHtml[$top20Url] ?? null);
                } catch (\Throwable $e) {
                    $stats['profiles_skipped']++;
                    $stats['errors'][] = [
                        'url' => (string) ($card['url'] ?? ''),
                        'name' => (string) ($card['name'] ?? ''),
                        'error' => $e->getMessage(),
                    ];
                    $this->syncImportItem($importBatch, $card, $category, [
                        'status' => 'error',
                        'error_message' => $e->getMessage(),
                    ], $options);
                    $this->refreshImportBatchProgress($importBatch);
                    continue;
                }

                if (($result['skipped'] ?? false) === true) {
                    $stats['profiles_skipped']++;
                    $this->syncImportItem($importBatch, $card, $category, [
                        'status' => 'skipped',
                        'error_message' => $result['error_message'] ?? null,
                    ], $options);
                    $this->refreshImportBatchProgress($importBatch);
                    continue;
                }

                $stats['profiles_created'] += (int) ($result['profile_created'] ?? 0);
                $stats['profiles_updated'] += (int) ($result['profile_updated'] ?? 0);
                $stats['reviews_created'] += (int) ($result['reviews_created'] ?? 0);
                $stats['reviews_updated'] += (int) ($result['reviews_updated'] ?? 0);
                $stats['reviews_hidden'] += (int) ($result['reviews_hidden'] ?? 0);

                $this->syncImportItem($importBatch, $card, $category, [
                    'profile_id' => $result['profile_id'] ?? null,
                    'status' => 'imported',
                    'reviews_created' => $result['reviews_created'] ?? 0,
                    'reviews_updated' => $result['reviews_updated'] ?? 0,
                    'reviews_hidden' => $result['reviews_hidden'] ?? 0,
                    'profile_was_created' => (bool) ($result['profile_created'] ?? false),
                    'profile_was_updated' => (bool) ($result['profile_updated'] ?? false),
                ], $options);
                $this->refreshImportBatchProgress($importBatch);
            }
        }

        $this->refreshImportBatchProgress($importBatch);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{items: array<int, array<string, mixed>>, _meta: array<string, mixed>}
     */
    public function collectListingCards(string $listingUrl, array $options = []): array
    {
        $configuredMaxPages = max(1, (int) config('top20_bulk_import.max_listing_pages', 250));
        $startPage = max(1, (int) ($options['start_page'] ?? 1));
        $requestedEndPage = $options['end_page'] ?? null;
        $requestedMaxPages = $options['max_pages'] ?? null;
        $maxPagesWindow = $requestedMaxPages === null || $requestedMaxPages === ''
            ? $configuredMaxPages
            : max(1, min($configuredMaxPages, (int) $requestedMaxPages));
        $endPage = filled($requestedEndPage)
            ? max($startPage, min($startPage + $configuredMaxPages - 1, (int) $requestedEndPage))
            : ($startPage + $maxPagesWindow - 1);
        $maxProfiles = max(0, (int) ($options['max_profiles'] ?? 0));
        $minReviews = max(0, (int) ($options['min_reviews'] ?? 1));
        $baseListingUrl = $this->normalizeTop20ListingBaseUrl($listingUrl);

        $itemsByUrl = [];
        $pagesVisited = 0;
        $pageSignatures = [];
        $pageUrlOverrides = [];

        if ($startPage > 1) {
            $firstPageHtml = $this->fetchHtml($this->buildTop20ListingPageUrl($baseListingUrl, 1));
            if ($firstPageHtml !== null) {
                foreach ($this->extractPaginationUrls($firstPageHtml, $baseListingUrl) as $paginationUrl) {
                    $resolvedPage = $this->extractTop20ListingPageNumber($paginationUrl);
                    if ($resolvedPage !== null) {
                        $pageUrlOverrides[$resolvedPage] = $paginationUrl;
                    }
                }
            }
        }

        for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) {
            $pageUrl = $pageUrlOverrides[$pageNumber] ?? $this->buildTop20ListingPageUrl($baseListingUrl, $pageNumber);
            $html = $this->fetchHtml($pageUrl);
            if ($html === null) {
                if ($pageNumber > $startPage) {
                    break;
                }

                continue;
            }

            $pagesVisited++;
            $pageCards = $this->parseListingCards($html, $options['city_name'] ?? null);
            if ($pageCards === []) {
                if ($pageNumber > $startPage) {
                    break;
                }

                continue;
            }

            $pageSignature = $this->buildListingPageSignature($pageCards);
            if ($pageSignature !== '' && isset($pageSignatures[$pageSignature])) {
                break;
            }

            if ($pageSignature !== '') {
                $pageSignatures[$pageSignature] = true;
            }

            foreach ($this->extractPaginationUrls($html, $pageUrl) as $paginationUrl) {
                $resolvedPage = $this->extractTop20ListingPageNumber($paginationUrl);
                if ($resolvedPage !== null) {
                    $pageUrlOverrides[$resolvedPage] = $paginationUrl;
                }
            }

            foreach ($pageCards as $card) {
                $companyUrl = (string) ($card['url'] ?? '');
                if ($companyUrl === '' || isset($itemsByUrl[$companyUrl])) {
                    continue;
                }

                if ((int) ($card['reviews_count'] ?? 0) < $minReviews) {
                    continue;
                }

                $itemsByUrl[$companyUrl] = $card;
                if ($maxProfiles > 0 && count($itemsByUrl) >= $maxProfiles) {
                    break 2;
                }
            }
        }

        return [
            'items' => array_values($itemsByUrl),
            '_meta' => [
                'pages_visited' => $pagesVisited,
                'start_page' => $startPage,
                'end_page' => $endPage,
            ],
        ];
    }

    private function normalizeTop20ListingBaseUrl(string $url): string
    {
        $absoluteUrl = $this->normalizeAbsoluteUrl($url);
        if ($absoluteUrl === '') {
            return '';
        }

        $parts = parse_url($absoluteUrl);
        if ($parts === false) {
            return rtrim($absoluteUrl, '/');
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = preg_replace('#^/ru(?=/|$)#i', '', $path) ?: '/';
        $path = preg_replace('#/page/\d+/?$#i', '', $path) ?: '/';
        $path = rtrim($path, '/');

        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['page']);
        $queryString = http_build_query($query);

        return 'https://top20.ua'
            . ($path !== '' ? $path : '/')
            . ($queryString !== '' ? ('?' . $queryString) : '');
    }

    private function buildTop20ListingPageUrl(string $baseUrl, int $pageNumber): string
    {
        $baseUrl = $this->normalizeTop20ListingBaseUrl($baseUrl);
        if ($baseUrl === '') {
            return '';
        }

        $parts = parse_url($baseUrl);
        if ($parts === false) {
            return rtrim($baseUrl, '/');
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';
        $path = rtrim($path, '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if ($pageNumber > 1) {
            // Шляхова пагінація `/page/N/` працює лише до 10-ї сторінки — далі Top20 віддає
            // 301 на першу сторінку. Query-пагінація `?page=N` віддає всі сторінки для
            // всіх типів лістингів (категорії, `.html`, теги).
            $query['page'] = $pageNumber;
        }

        if (! preg_match('#\.html$#i', $path)) {
            // Без trailing slash Top20 віддає 404 на query-пагінацію.
            $path = rtrim($path, '/') . '/';
        }

        $queryString = http_build_query($query);

        return 'https://top20.ua'
            . ($path !== '' ? $path : '/')
            . ($queryString !== '' ? ('?' . $queryString) : '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageCards
     */
    private function buildListingPageSignature(array $pageCards): string
    {
        $urls = [];

        foreach (array_slice($pageCards, 0, 3) as $card) {
            $url = trim((string) ($card['url'] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls === [] ? '' : implode('|', $urls);
    }

    private function profileFetchConcurrency(): int
    {
        return max(1, min(8, (int) config('top20_bulk_import.profile_fetch_concurrency', 4)));
    }

    private function reviewFetchConcurrency(): int
    {
        return max(1, min(12, (int) config('top20_bulk_import.review_fetch_concurrency', 8)));
    }

    /**
     * Fetches only company profile HTML concurrently. Parsing, media downloads and DB writes
     * stay sequential to avoid duplicate profiles and database lock contention.
     *
     * @param  array<int, array<string, mixed>>  $cards
     * @return array<string, string>
     */
    private function fetchCompanyHtmlBatch(array $cards): array
    {
        $urls = [];
        foreach ($cards as $card) {
            $url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
            if ($url !== '') {
                $urls[$url] = $url;
            }
        }

        if ($urls === []) {
            return [];
        }

        return $this->fetchTop20HtmlBatch(array_values($urls), $this->profileFetchConcurrency());
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $options
     * @return array<string, int|bool>
     */
    private function importCompanyCard(array $card, Category $category, array $options, ?string $prefetchedHtml = null): array
    {
        $top20Url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
        if ($top20Url === '') {
            return ['skipped' => true];
        }

        $details = $this->fetchCompanyDetails($top20Url, $prefetchedHtml);
        if (($details['name'] ?? '') === '' && ($card['name'] ?? '') === '') {
            return ['skipped' => true];
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        if (! $dryRun) {
            $details = $this->localizeImportedDetailMedia($details);
        }

        $refreshExisting = (bool) ($options['refresh_existing'] ?? false);
        $cityName = $this->canonicalImportedCity((string) ($options['city_name'] ?? $details['city'] ?? $card['city'] ?? ''));
        $regionId = $this->resolveImportRegionId((string) ($options['region_name'] ?? ''), $cityName);

        if ($dryRun) {
            return [
                'profile_created' => 0,
                'profile_updated' => 0,
                'reviews_created' => count($details['reviews'] ?? []),
                'reviews_updated' => 0,
                'skipped' => false,
            ];
        }

        return $this->runTransactionWithRetry(function () use ($card, $category, $top20Url, $details, $refreshExisting, $cityName, $regionId, $options): array {
            $existing = $this->findExistingProfile($top20Url, $details, $card);
            $profile = $existing ?: new Profile([
                'slug' => $this->uniqueProfileSlug((string) ($details['name'] ?? $card['name'] ?? 'Профіль')),
            ]);
            $isNew = ! $profile->exists;
            $subcategoryId = $this->resolveSubcategoryId($category, $options);
            $publishImportedProfiles = (bool) ($options['publish_profiles'] ?? false);
            $requestedByUserId = filled($options['requested_by_user_id'] ?? null)
                ? (int) $options['requested_by_user_id']
                : null;

            $payload = $this->buildProfilePayload($card, $details, $cityName, $regionId);
            $this->fillProfileFromImport($profile, $payload, $refreshExisting || $isNew);
            $this->syncImportedTop20Content($profile, $details);

            if ($isNew) {
                $profile->status = $publishImportedProfiles ? 'active' : 'draft';
                $profile->is_published = $publishImportedProfiles;
                $profile->show_in_catalog = $publishImportedProfiles;
                $profile->created_by_user_id = $requestedByUserId;
            }

            if ($requestedByUserId) {
                $profile->updated_by_user_id = $requestedByUserId;
            }

            if ($isNew && blank($profile->internal_note)) {
                $profile->internal_note = 'Імпортовано з зовнішнього каталогу. Перевірити профіль і вирішити щодо публікації.';
            }

            $this->persistImportedProfile($profile);
            $this->ensureProfileLogoFallback($profile);

            $this->syncImportedProfileCategories($profile, (int) $category->id, $subcategoryId, $isNew);
            $this->syncImportedDirections($profile, $category, $details);

            $this->syncTop20ProfileDataSource($profile, $top20Url, $card, $details);

            $reviewStats = $this->syncExternalReviews($profile, $details);
            $this->normalizeImportedReviewSources($profile);
            $reviewStats['deleted'] = $this->pruneImportedExternalReviews($profile, (int) config('top20_bulk_import.max_reviews_per_profile', 100));
            $hiddenAiDrafts = $this->hideConflictingPublishedAiDrafts($profile);
            app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $profile->id);

            return [
                'profile_id' => $profile->id,
                'profile_created' => $isNew ? 1 : 0,
                'profile_updated' => $isNew ? 0 : 1,
                'reviews_created' => $reviewStats['created'],
                'reviews_updated' => $reviewStats['updated'],
                'reviews_deleted' => $reviewStats['deleted'],
                'reviews_hidden' => $hiddenAiDrafts,
                'skipped' => false,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function buildProfilePayload(array $card, array $details, string $cityName, ?int $regionId): array
    {
        $description = trim((string) ($details['description'] ?? $card['teaser'] ?? $card['description'] ?? ''));
        $descriptionPlain = trim((string) ($details['description_plain'] ?? html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $shortDescription = trim((string) ($details['short_description'] ?? ''));
        if ($shortDescription === '') {
            $shortDescription = Str::limit($descriptionPlain, 280, '...');
        }
        $phones = array_values(array_filter(array_map('trim', (array) ($details['phones'] ?? []))));
        $addresses = array_values(array_filter(array_map('trim', (array) ($details['addresses'] ?? []))));
        $resolvedCity = $this->canonicalImportedCity($cityName !== '' ? $cityName : (string) ($details['city'] ?? ''));
        $address = $this->localizeImportedAddress(
            $addresses[0] ?? trim((string) ($details['address'] ?? $card['address'] ?? '')),
            $resolvedCity
        );
        $name = $this->normalizeImportedSingleLineText((string) ($details['name'] ?? $card['name'] ?? ''));

        return [
            'region_id' => $regionId,
            'type' => 'company',
            'name' => $name,
            'short_description' => $shortDescription !== '' ? $shortDescription : null,
            'description' => $description !== '' ? $description : null,
            'website' => $this->nullIfBlank((string) ($details['website'] ?? '')),
            'contact_cta_url' => $this->nullIfBlank((string) ($details['website'] ?? '')),
            'email' => $this->nullIfBlank((string) ($details['email'] ?? '')),
            'phone' => $phones !== [] ? implode(', ', array_unique($phones)) : $this->nullIfBlank((string) ($details['phone'] ?? '')),
            'address' => $address !== '' ? $address : null,
            'city' => $resolvedCity !== '' ? $resolvedCity : $this->nullIfBlank((string) ($details['city'] ?? '')),
            'district' => $this->extractDistrict((string) ($card['address'] ?? $address)),
            'logo_url' => $this->nullIfBlank((string) ($details['avatar_image'] ?? '')),
            'banner_url' => $this->nullIfBlank((string) ($details['additional_image'] ?? $details['image'] ?? '')),
            'gallery' => $this->normalizeImportedGallery((array) ($details['additional_images'] ?? [])),
            'social_links' => $this->normalizeImportedSocialLinks((array) ($details['social_links'] ?? [])),
            'og_image_url' => $this->nullIfBlank((string) ($details['image'] ?? '')),
            'rating_avg' => is_numeric($details['aggregate_rating'] ?? null)
                ? round((float) $details['aggregate_rating'], 2)
                : (is_numeric($card['rating'] ?? null) ? round((float) $card['rating'], 2) : 0),
            'reviews_count' => max(
                (int) ($details['aggregate_count'] ?? 0),
                (int) ($card['reviews_count'] ?? 0)
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function localizeImportedDetailMedia(array $details): array
    {
        $profileName = trim((string) ($details['name'] ?? 'profile'));

        $details['avatar_image'] = $this->localizeImportedImageReference(
            (string) ($details['avatar_image'] ?? ''),
            'profiles',
            $profileName
        );
        $details['image'] = $this->localizeImportedImageReference(
            (string) ($details['image'] ?? ''),
            'profiles',
            $profileName
        );
        $details['additional_image'] = $this->localizeImportedImageReference(
            (string) ($details['additional_image'] ?? ''),
            'profiles',
            $profileName
        );
        $details['additional_images'] = collect((array) ($details['additional_images'] ?? []))
            ->map(fn ($image) => $this->localizeImportedImageReference((string) $image, 'gallery', $profileName))
            ->filter(fn ($image) => filled($image))
            ->unique()
            ->values()
            ->all();
        $details['reviews'] = collect((array) ($details['reviews'] ?? []))
            ->map(function ($review) use ($profileName) {
                if (! is_array($review)) {
                    return $review;
                }

                $author = trim((string) ($review['review_author'] ?? '')) ?: $profileName;
                $rawAvatarUrl = (string) ($review['review_author_avatar_url'] ?? '');

                $review['review_author_avatar_url'] = (bool) config('top20_bulk_import.download_review_avatars', true)
                    ? $this->localizeImportedImageReference(
                        $rawAvatarUrl,
                        'review-avatars',
                        $author,
                        (int) config('top20_bulk_import.review_avatar_max_bytes', 2 * 1024 * 1024)
                    )
                    : $this->normalizeExternalAvatarUrl($rawAvatarUrl);

                return $review;
            })
            ->all();

        if ($this->normalizeStoredMediaPath((string) ($details['image'] ?? '')) === null) {
            $details['image'] = $this->normalizeStoredMediaPath((string) ($details['additional_image'] ?? ''))
                ?? $this->normalizeStoredMediaPath((string) ($details['avatar_image'] ?? ''))
                ?? $details['image'];
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function fillProfileFromImport(Profile $profile, array $payload, bool $overwrite): void
    {
        foreach ($payload as $field => $value) {
            if ($field === 'name') {
                if ($value !== null && trim((string) $value) !== '') {
                    $profile->{$field} = $value;
                }
                continue;
            }

            if (in_array($field, ['status', 'is_published', 'show_in_catalog', 'rating_avg', 'reviews_count', 'region_id'], true)) {
                $profile->{$field} = $value;
                continue;
            }

            if (in_array($field, ['website', 'contact_cta_url'], true)) {
                $incoming = trim((string) ($value ?? ''));
                $current = trim((string) ($profile->{$field} ?? ''));

                if ($incoming !== '' && ($overwrite || $current === '' || $this->isTop20PlaceholderWebsite($current))) {
                    $profile->{$field} = $incoming;
                }

                continue;
            }

            if ($field === 'logo_url') {
                $incoming = trim((string) ($value ?? ''));
                $current = trim((string) ($profile->{$field} ?? ''));

                if ($incoming !== '' && ($overwrite || $current === '' || GeneratedProfileLogo::isGenerated($current))) {
                    $profile->{$field} = $incoming;
                }

                continue;
            }

            $current = $profile->{$field} ?? null;
            if ($overwrite || blank($current)) {
                $profile->{$field} = $value;
            }
        }
    }

    private function ensureProfileLogoFallback(Profile $profile): void
    {
        $logo = trim((string) ($profile->logo_url ?? ''));
        if ($logo !== '' && MediaUrl::publicImageUrl($logo) !== null && ! GeneratedProfileLogo::isGenerated($logo)) {
            return;
        }

        $fallbackPath = GeneratedProfileLogo::ensure($profile);
        if ($logo !== $fallbackPath) {
            $profile->logo_url = $fallbackPath;
            $profile->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array{created:int,updated:int}
     */
    private function syncExternalReviews(Profile $profile, array $details): array
    {
        $created = 0;
        $updated = 0;
        $hasExistingImportedReviews = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where(function ($query): void {
                $query->where('verification_type', 'external_google_import')
                    ->orWhere('verification_type', 'external_top20_import')
                    ->orWhere('external_source_type', 'google')
                    ->orWhere('external_source_type', 'top20');
            })
            ->exists();
        $seenIncomingHashes = [];

        foreach ((array) ($details['reviews'] ?? []) as $review) {
            if (! is_array($review)) {
                continue;
            }

            $rawBody = trim((string) ($review['review_text'] ?? ''));
            $body = $this->sanitizeTop20ReviewText($rawBody);
            if ($body === '') {
                $body = 'Відгук імпортовано з Top20 без текстового опису.';
            }

            $rating = max(1, min(5, (int) round((float) ($review['review_rating'] ?? 0))));
            if ($rating < 1) {
                continue;
            }

            $author = trim((string) ($review['review_author'] ?? '')) ?: 'Користувач';
            $sourceUrl = $this->buildReviewSourceUrl(
                (string) ($details['top20_url'] ?? ''),
                (string) ($review['_rid'] ?? '')
            );

            $identity = [
                'url' => $sourceUrl,
                'source_type' => 'google',
                'review_author' => $author,
                'review_rating' => $rating,
                'review_date' => $review['review_date'] ?? null,
                'review_text' => $body,
            ];
            $legacyIdentity = $rawBody !== '' && $rawBody !== $body
                ? [
                    ...$identity,
                    'review_text' => $rawBody,
                ]
                : null;

            $hash = $this->buildExternalReviewHash($identity);
            $legacyWidgetHash = $this->buildExternalReviewHash([
                ...$identity,
                'source_type' => 'top20',
            ]);
            $legacyHash = $this->buildExternalReviewHash([
                ...$identity,
                'source_type' => 'top20',
                'url' => (string) ($details['top20_url'] ?? ''),
            ]);
            $legacyRawHash = $legacyIdentity ? $this->buildExternalReviewHash($legacyIdentity) : null;
            $legacyRawWidgetHash = $legacyIdentity ? $this->buildExternalReviewHash([
                ...$legacyIdentity,
                'source_type' => 'top20',
            ]) : null;
            $legacyRawTop20Hash = $legacyIdentity ? $this->buildExternalReviewHash([
                ...$legacyIdentity,
                'source_type' => 'top20',
                'url' => (string) ($details['top20_url'] ?? ''),
            ]) : null;
            $publishedAt = $this->normalizeExternalReviewDate($review['review_date'] ?? null);
            $candidateHashes = array_values(array_unique(array_filter([
                $hash,
                $legacyWidgetHash,
                $legacyHash,
                $legacyRawHash,
                $legacyRawWidgetHash,
                $legacyRawTop20Hash,
            ])));

            if (isset($seenIncomingHashes[$hash])) {
                continue;
            }

            $existing = null;
            if ($hasExistingImportedReviews) {
                $existing = ProfileReview::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('external_review_hash', $candidateHashes)
                    ->first();

                if (! $existing) {
                    $existing = ProfileReview::query()
                        ->where('profile_id', $profile->id)
                        ->where('rating', $rating)
                        ->where('body', $body)
                        ->where(function ($query) use ($author): void {
                            $query->where('external_review_author', $author)
                                ->orWhere('author_name', $author);
                        })
                        ->when(
                            $publishedAt,
                            fn ($query) => $query->where('external_review_date', $publishedAt),
                            fn ($query) => $query->whereNull('external_review_date')
                        )
                        ->where(function ($query): void {
                            $query->where('verification_type', 'external_google_import')
                                ->orWhere('verification_type', 'external_top20_import')
                                ->orWhere('external_source_type', 'google')
                                ->orWhere('external_source_type', 'top20');
                        })
                        ->first();
                }
            }

            $rawAvatarUrl = (string) ($review['review_author_avatar_url'] ?? '');
            $authorAvatarUrl = (bool) config('top20_bulk_import.download_review_avatars', true)
                ? $this->localizeImportedImageReference(
                    $rawAvatarUrl,
                    'review-avatars',
                    $author,
                    (int) config('top20_bulk_import.review_avatar_max_bytes', 2 * 1024 * 1024)
                ) ?? $this->normalizeExternalAvatarUrl($rawAvatarUrl)
                : $this->normalizeExternalAvatarUrl($rawAvatarUrl);

            $payload = [
                'author_name' => $author,
                'rating' => $rating,
                'title' => null,
                'body' => $body,
                'status' => 'published',
                'verification_type' => 'external_google_import',
                'published_at' => $publishedAt ? Carbon::parse($publishedAt) : now(),
                'external_source_url' => null,
                'external_review_hash' => $hash,
                'external_source_type' => 'google',
                'external_review_author' => $author,
                'external_review_author_avatar_url' => $authorAvatarUrl,
                'external_review_date' => $publishedAt,
                'moderation_note' => 'Імпортовано як зовнішній відгук Google.',
            ];

            if ($existing) {
                $existing->fill(array_filter($payload, fn ($value, $key) => $value !== null || $key === 'external_source_url', ARRAY_FILTER_USE_BOTH));
                $existing->external_source_url = $payload['external_source_url'];
                $existing->external_review_author_avatar_url = $authorAvatarUrl;

                if ($existing->status === 'pending' && (string) ($existing->verification_type ?? '') === 'external_ai_draft') {
                    $existing->status = 'published';
                    $existing->published_at = $payload['published_at'];
                    $existing->verification_type = 'external_google_import';
                }

                $existing->saveQuietly();
                foreach ($candidateHashes as $candidateHash) {
                    $seenIncomingHashes[$candidateHash] = true;
                }
                $updated++;
                continue;
            }

            $profile->reviews()->createQuietly($payload);
            foreach ($candidateHashes as $candidateHash) {
                $seenIncomingHashes[$candidateHash] = true;
            }
            $created++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function hideConflictingPublishedAiDrafts(Profile $profile): int
    {
        $reviews = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where('verification_type', 'external_ai_draft')
            ->where('status', 'published')
            ->where(function ($query): void {
                $query->whereNull('external_source_type')
                    ->orWhere('external_source_type', '!=', 'google');
            })
            ->get();

        $hidden = 0;

        foreach ($reviews as $review) {
            $note = trim((string) $review->moderation_note);
            $review->status = 'hidden';
            $review->moderation_note = trim(implode("\n", array_filter([
                $note,
                'Приховано автоматично після authoritative Top20 bulk import.',
            ])));
            $review->saveQuietly();
            $hidden++;
        }

        return $hidden;
    }

    private function normalizeImportedReviewSources(Profile $profile): void
    {
        ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where(function ($query): void {
                $query->where('verification_type', 'external_google_import')
                    ->orWhere('verification_type', 'external_top20_import')
                    ->orWhere('external_source_type', 'top20');
            })
            ->update([
                'verification_type' => 'external_google_import',
                'external_source_type' => 'google',
                'external_source_url' => null,
                'moderation_note' => 'Імпортовано як зовнішній відгук Google.',
                'updated_at' => now(),
            ]);
    }

    private function pruneImportedExternalReviews(Profile $profile, int $keepLimit): int
    {
        $keepLimit = max(1, min(1000, $keepLimit));

        $reviewIdsToKeep = ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where(function ($query): void {
                $query->where('verification_type', 'external_google_import')
                    ->orWhere('verification_type', 'external_top20_import')
                    ->orWhere('external_source_type', 'top20')
                    ->orWhere(function ($query): void {
                        $query->where('external_source_type', 'google')
                            ->whereNull('external_source_url');
                    });
            })
            ->orderByRaw('CASE WHEN external_review_date IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('external_review_date')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($keepLimit)
            ->pluck('id');

        if ($reviewIdsToKeep->isEmpty()) {
            return 0;
        }

        return ProfileReview::query()
            ->where('profile_id', $profile->id)
            ->where(function ($query): void {
                $query->where('verification_type', 'external_google_import')
                    ->orWhere('verification_type', 'external_top20_import')
                    ->orWhere('external_source_type', 'top20')
                    ->orWhere(function ($query): void {
                        $query->where('external_source_type', 'google')
                            ->whereNull('external_source_url');
                    });
            })
            ->whereNotIn('id', $reviewIdsToKeep)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $options
     */
    private function syncImportItem(?Top20ImportBatch $batch, array $card, Category $category, array $result, array $options): void
    {
        if (! $batch) {
            return;
        }

        $top20Url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
        if ($top20Url === '') {
            return;
        }

        $subcategoryId = $this->resolveSubcategoryId($category, $options);

        Top20ImportItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                'top20_url' => $top20Url,
            ],
            [
                'profile_id' => $result['profile_id'] ?? null,
                'category_id' => $category->id,
                'subcategory_id' => $subcategoryId,
                'status' => (string) ($result['status'] ?? 'imported'),
                'raw_name' => (string) ($card['name'] ?? ''),
                'raw_city' => (string) ($card['city'] ?? ($options['city_name'] ?? '')),
                'raw_payload' => $card,
                'reviews_created' => (int) ($result['reviews_created'] ?? 0),
                'reviews_updated' => (int) ($result['reviews_updated'] ?? 0),
                'reviews_hidden' => (int) ($result['reviews_hidden'] ?? 0),
                'profile_was_created' => (bool) ($result['profile_was_created'] ?? $result['profile_created'] ?? false),
                'profile_was_updated' => (bool) ($result['profile_was_updated'] ?? $result['profile_updated'] ?? false),
                'error_message' => $result['error_message'] ?? null,
                'processed_at' => now(),
            ]
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $cards
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $stats
     */
    private function primeImportBatchProgress(?Top20ImportBatch $batch, array $cards, Category $category, array $options, array $stats): void
    {
        if (! $batch) {
            return;
        }

        $subcategoryId = $this->resolveSubcategoryId($category, $options);

        foreach ($cards as $card) {
            if (! is_array($card)) {
                continue;
            }

            $top20Url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
            if ($top20Url === '') {
                continue;
            }

            Top20ImportItem::query()->updateOrCreate(
                [
                    'batch_id' => $batch->id,
                    'top20_url' => $top20Url,
                ],
                [
                    'profile_id' => null,
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategoryId,
                    'status' => 'pending',
                    'raw_name' => (string) ($card['name'] ?? ''),
                    'raw_city' => (string) ($card['city'] ?? ($options['city_name'] ?? '')),
                    'raw_payload' => $card,
                    'reviews_created' => 0,
                    'reviews_updated' => 0,
                    'reviews_hidden' => 0,
                    'profile_was_created' => false,
                    'profile_was_updated' => false,
                    'error_message' => null,
                    'processed_at' => null,
                ]
            );
        }

        $optionsPayload = $batch->options ?? [];
        $optionsPayload['progress'] = [
            'processed_items' => 0,
            'total_items' => count($cards),
            'percent' => 0,
            'pending_items' => count($cards),
            'current' => null,
            'updated_at' => now()->toIso8601String(),
        ];

        $batch->update([
            'status' => count($cards) > 0 ? 'processing' : 'completed',
            'pages_visited' => (int) ($stats['pages_visited'] ?? 0),
            'cards_found' => (int) ($stats['cards_found'] ?? 0),
            'profiles_considered' => count($cards),
            'finished_at' => count($cards) > 0 ? null : now(),
            'options' => $optionsPayload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $options
     */
    private function markImportItemProcessing(?Top20ImportBatch $batch, array $card, Category $category, array $options, int $position): void
    {
        if (! $batch) {
            return;
        }

        $top20Url = $this->normalizeTop20ProfileUrl((string) ($card['url'] ?? ''));
        if ($top20Url === '') {
            return;
        }

        $subcategoryId = $this->resolveSubcategoryId($category, $options);

        Top20ImportItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                'top20_url' => $top20Url,
            ],
            [
                'category_id' => $category->id,
                'subcategory_id' => $subcategoryId,
                'status' => 'processing',
                'raw_name' => (string) ($card['name'] ?? ''),
                'raw_city' => (string) ($card['city'] ?? ($options['city_name'] ?? '')),
                'raw_payload' => $card,
                'error_message' => null,
                'processed_at' => null,
            ]
        );

        $batch->refresh();
        $optionsPayload = $batch->options ?? [];
        $progress = (array) ($optionsPayload['progress'] ?? []);
        $progress['current'] = [
            'position' => $position,
            'name' => (string) ($card['name'] ?? ''),
            'url' => $top20Url,
        ];
        $progress['updated_at'] = now()->toIso8601String();
        $optionsPayload['progress'] = $progress;

        $batch->update([
            'status' => 'processing',
            'options' => $optionsPayload,
        ]);
    }

    private function refreshImportBatchProgress(?Top20ImportBatch $batch): void
    {
        if (! $batch) {
            return;
        }

        $batch->refresh();

        $total = (int) $batch->items()->count();
        $processed = (int) $batch->items()->whereIn('status', ['imported', 'skipped', 'error'])->count();
        $pending = (int) $batch->items()->where('status', 'pending')->count();
        $errors = (int) $batch->items()->where('status', 'error')->count();
        $current = $batch->items()
            ->where('status', 'processing')
            ->orderByDesc('updated_at')
            ->first();

        $optionsPayload = $batch->options ?? [];
        $progress = (array) ($optionsPayload['progress'] ?? []);
        $progress['processed_items'] = $processed;
        $progress['total_items'] = $total;
        $progress['percent'] = $total > 0 ? (int) floor(($processed / $total) * 100) : 100;
        $progress['pending_items'] = $pending;
        $progress['current'] = $current ? [
            'position' => (int) ($processed + 1),
            'name' => (string) ($current->raw_name ?? ''),
            'url' => (string) ($current->top20_url ?? ''),
        ] : null;
        $progress['updated_at'] = now()->toIso8601String();
        $optionsPayload['progress'] = $progress;

        $updates = [
            'errors_count' => $errors,
            'options' => $optionsPayload,
        ];

        if ($total === 0) {
            $updates['status'] = 'completed';
            $updates['finished_at'] = now();
        } elseif ($processed >= $total) {
            $updates['status'] = $errors > 0 ? 'completed_with_errors' : 'completed';
            $updates['finished_at'] = now();
        } else {
            $updates['status'] = 'processing';
            $updates['finished_at'] = null;
        }

        $batch->update($updates);
    }

    public function resolveProfileTop20Url(Profile $profile): ?string
    {
        $sourceUrl = ProfileDataSource::query()
            ->where('profile_id', $profile->id)
            ->whereIn('source_type', ['top20_import', 'top20'])
            ->whereNotNull('url')
            ->orderByDesc('id')
            ->value('url');

        if (filled($sourceUrl)) {
            return $this->normalizeTop20ProfileUrl((string) $sourceUrl);
        }

        $itemUrl = Top20ImportItem::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('top20_url')
            ->orderByDesc('id')
            ->value('top20_url');

        return filled($itemUrl) ? $this->normalizeTop20ProfileUrl((string) $itemUrl) : null;
    }

    /**
     * @return array<int, array{url:string,source:string}>
     */
    private function collectProfileLogoCandidates(Profile $profile, bool $fetchTop20, ?string $top20Url, bool $preferTop20 = false): array
    {
        $top20Candidates = [];
        $storedCandidates = [];
        $currentCandidates = [];
        $currentLogo = trim((string) ($profile->logo_url ?? ''));
        $profileName = trim((string) ($profile->name ?? ''));

        if ($currentLogo !== '' && (preg_match('#^https?://#i', $currentLogo) || str_starts_with($currentLogo, '//'))) {
            $currentCandidates[] = [
                'url' => $currentLogo,
                'source' => 'current_remote_logo',
            ];
        }

        foreach ($profile->dataSources as $source) {
            if (! in_array((string) $source->source_type, ['top20_import', 'top20'], true)) {
                continue;
            }

            $foundFields = is_array($source->found_fields) ? $source->found_fields : [];
            $candidateName = trim((string) (
                data_get($foundFields, 'detail.name')
                ?? data_get($foundFields, 'listing.name')
                ?? $source->title
                ?? ''
            ));

            if ($candidateName !== '' && ! $this->isLikelyMatchingProfileName($profileName, $candidateName)) {
                continue;
            }

            foreach ([
                data_get($foundFields, 'detail.avatar_image'),
                data_get($foundFields, 'detail.image'),
                data_get($foundFields, 'detail.additional_image'),
            ] as $candidateUrl) {
                $candidateUrl = trim((string) $candidateUrl);
                if ($candidateUrl === '') {
                    continue;
                }

                $storedCandidates[] = [
                    'url' => $candidateUrl,
                    'source' => 'profile_data_source',
                ];
            }
        }

        if ($fetchTop20 && filled($top20Url)) {
            try {
                $details = $this->fetchCompanyDetails((string) $top20Url);
                $candidateName = trim((string) ($details['name'] ?? ''));
                if ($candidateName !== '' && ! $this->isLikelyMatchingProfileName($profileName, $candidateName)) {
                    $details = [];
                }

                foreach ([
                    $details['avatar_image'] ?? null,
                    $details['image'] ?? null,
                    $details['additional_image'] ?? null,
                ] as $candidateUrl) {
                    $candidateUrl = trim((string) $candidateUrl);
                    if ($candidateUrl === '') {
                        continue;
                    }

                    $top20Candidates[] = [
                        'url' => $candidateUrl,
                        'source' => 'top20_fetched_detail',
                    ];
                }
            } catch (\Throwable) {
                // Keep repair flow resilient for bulk runs.
            }
        }

        $candidates = $preferTop20
            ? array_merge($top20Candidates, $storedCandidates, $currentCandidates)
            : array_merge($currentCandidates, $storedCandidates, $top20Candidates);

        return collect($candidates)
            ->map(function (array $item): ?array {
                $url = trim((string) ($item['url'] ?? ''));
                if ($url === '') {
                    return null;
                }

                return [
                    'url' => $url,
                    'source' => (string) ($item['source'] ?? 'candidate'),
                ];
            })
            ->filter()
            ->unique(fn (array $item) => (string) $item['url'])
            ->values()
            ->all();
    }

    private function isLikelyMatchingProfileName(string $profileName, string $candidateName): bool
    {
        $left = $this->normalizeProfileNameTokens($profileName);
        $right = $this->normalizeProfileNameTokens($candidateName);

        if ($left !== [] && $right !== []) {
            $intersection = array_values(array_intersect($left, $right));
            $overlapCount = count($intersection);

            if ($overlapCount >= 2) {
                return true;
            }

            if ($overlapCount >= 1 && ($overlapCount / max(1, min(count($left), count($right)))) >= 0.5) {
                return true;
            }
        }

        $normalizedProfile = $this->normalizeProfileNameForComparison($profileName);
        $normalizedCandidate = $this->normalizeProfileNameForComparison($candidateName);

        if ($normalizedProfile === '' || $normalizedCandidate === '') {
            return false;
        }

        if ($normalizedProfile === $normalizedCandidate) {
            return true;
        }

        if (strlen($normalizedProfile) >= 6 && str_contains($normalizedCandidate, $normalizedProfile)) {
            return true;
        }

        if (strlen($normalizedCandidate) >= 6 && str_contains($normalizedProfile, $normalizedCandidate)) {
            return true;
        }

        similar_text($normalizedProfile, $normalizedCandidate, $percent);

        return $percent >= 72.0;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeProfileNameTokens(string $value): array
    {
        $normalized = $this->normalizeProfileNameForComparison($value);
        if ($normalized === '') {
            return [];
        }

        $stopWords = [
            'advokat',
            'advokatske',
            'advokatskii',
            'advokatskoe',
            'advokatska',
            'advokatskaya',
            'advokatura',
            'biuro',
            'bureau',
            'iuridicna',
            'iuridicne',
            'iuridicnii',
            'iuridiceskaia',
            'iuridiceskie',
            'kompaniia',
            'kompaniya',
            'kompaniya',
            'kompani',
            'obednannia',
            'obedinenie',
            'obiednannia',
            'obednannya',
            'agentstvo',
            'centr',
            'center',
            'grup',
            'group',
            'ta',
            'i',
            'the',
            'law',
            'legal',
        ];

        return array_values(array_unique(array_filter(
            preg_split('/\s+/u', $normalized) ?: [],
            static fn (string $token): bool => strlen($token) >= 3 && ! in_array($token, $stopWords, true)
        )));
    }

    private function normalizeProfileNameForComparison(string $value): string
    {
        $normalized = Str::lower(Str::ascii($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', $normalized) ?: '';

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?: '');
    }

    /**
     * @return array{category:?Category,subcategory_id:?int}
     */
    private function resolveProfileImportCategoryContext(Profile $profile): array
    {
        $selection = CategoryHierarchy::selectionFromProfile($profile);
        $rootCategoryId = $selection['category_id'] ? CategoryHierarchy::resolveRootCategoryId((int) $selection['category_id']) : null;
        $subcategoryId = $selection['subcategory_id'] ? (int) $selection['subcategory_id'] : null;

        $category = $rootCategoryId ? Category::find($rootCategoryId) : null;

        if (! $category) {
            $latestItem = Top20ImportItem::query()
                ->where('profile_id', $profile->id)
                ->whereNotNull('category_id')
                ->latest('id')
                ->first();

            if ($latestItem?->category_id) {
                $rootCategoryId = CategoryHierarchy::resolveRootCategoryId((int) $latestItem->category_id);
                $category = $rootCategoryId ? Category::find($rootCategoryId) : null;
            }

            if (! $subcategoryId && $latestItem?->subcategory_id) {
                $subcategoryId = (int) $latestItem->subcategory_id;
            }
        }

        return [
            'category' => $category,
            'subcategory_id' => $subcategoryId,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveSubcategoryId(Category $category, array $options): ?int
    {
        $subcategorySlug = trim((string) ($options['subcategory_slug'] ?? ''));
        if ($subcategorySlug === '') {
            return null;
        }

        $subcategory = Category::query()
            ->where('parent_id', $category->id)
            ->where('slug', $subcategorySlug)
            ->first();

        return $subcategory?->id ? (int) $subcategory->id : null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $card
     */
    private function findExistingProfile(string $top20Url, array $details, array $card): ?Profile
    {
        $existingBySource = $this->findExistingProfileByTop20SourceUrl($top20Url);

        if ($existingBySource) {
            return $existingBySource;
        }

        $name = trim((string) ($details['name'] ?? $card['name'] ?? ''));
        $city = $this->canonicalImportedCity((string) ($details['city'] ?? $card['city'] ?? ''));
        $cityLower = Str::lower($city);
        $sameCityScope = static function ($query) use ($cityLower): void {
            $query->whereNull('city')
                ->orWhere('city', '')
                ->orWhereRaw('lower(city) = ?', [$cityLower]);
        };

        $website = $this->normalizeWebsite((string) ($details['website'] ?? ''));
        if ($website !== null) {
            $profile = Profile::query()
                ->where('website', 'like', '%' . $website . '%')
                ->when($city !== '', fn ($query) => $query->where($sameCityScope))
                ->first();
            if ($profile) {
                return $profile;
            }
        }

        $phones = array_values(array_filter((array) ($details['phones'] ?? [])));
        foreach ($phones as $phone) {
            $digits = $this->normalizePhoneDigits((string) $phone);
            if ($digits === null) {
                continue;
            }

            $profile = Profile::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'+',''),' ',''),'-',''),'(',''),')','') LIKE ?", ['%' . $digits . '%'])
                ->when($city !== '', fn ($query) => $query->where($sameCityScope))
                ->first();
            if ($profile) {
                return $profile;
            }
        }

        $slug = Str::slug($name);

        if ($slug !== '') {
            $profile = Profile::query()
                ->where('slug', $slug)
                ->when($city !== '', fn ($query) => $query->whereRaw('lower(city) = ?', [Str::lower($city)]))
                ->first();
            if ($profile) {
                return $profile;
            }
        }

        if ($name !== '') {
            return Profile::query()
                ->whereRaw('lower(name) = ?', [Str::lower($name)])
                ->when($city !== '', fn ($query) => $query->whereRaw('lower(city) = ?', [Str::lower($city)]))
                ->first();
        }

        return null;
    }

    private function findExistingProfileByTop20SourceUrl(string $top20Url): ?Profile
    {
        $variants = $this->top20ProfileUrlVariants($top20Url);
        if ($variants === []) {
            return null;
        }

        $profileIds = ProfileDataSource::query()
            ->whereIn('source_type', ['top20', 'top20_import'])
            ->whereIn('url', $variants)
            ->whereNotNull('profile_id')
            ->pluck('profile_id')
            ->map(static fn (mixed $profileId): int => (int) $profileId)
            ->unique()
            ->values();

        if ($profileIds->isEmpty()) {
            return null;
        }

        return Profile::query()
            ->whereIn('id', $profileIds)
            ->orderByRaw('CASE WHEN is_published = 1 OR show_in_catalog = 1 OR status = ? THEN 0 ELSE 1 END', ['active'])
            ->orderByDesc('reviews_count')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array<string, mixed>  $details
     */
    private function syncTop20ProfileDataSource(Profile $profile, string $top20Url, array $card, array $details): void
    {
        $variants = $this->top20ProfileUrlVariants($top20Url);
        $dataSource = ProfileDataSource::query()
            ->where('source_type', 'top20_import')
            ->whereIn('url', $variants)
            ->orderByRaw('CASE WHEN profile_id = ? THEN 0 ELSE 1 END', [$profile->id])
            ->orderBy('id')
            ->first();

        if (! $dataSource) {
            $dataSource = new ProfileDataSource([
                'source_type' => 'top20_import',
            ]);
        }

        $dataSource->fill([
            'profile_id' => $profile->id,
            'source_type' => 'top20_import',
            'url' => $top20Url,
            'title' => 'Top20 import · ' . trim((string) ($details['name'] ?? $card['name'] ?? 'Компанія')),
            'found_fields' => [
                'listing' => $card,
                'detail' => [
                    'website' => $details['website'] ?? null,
                    'phones' => $details['phones'] ?? [],
                    'addresses' => $details['addresses'] ?? [],
                    'avatar_image' => $details['avatar_image'] ?? null,
                    'image' => $details['image'] ?? null,
                    'additional_image' => $details['additional_image'] ?? null,
                    'additional_images' => $details['additional_images'] ?? [],
                    'aggregate_rating' => $details['aggregate_rating'] ?? null,
                    'aggregate_count' => $details['aggregate_count'] ?? null,
                    'services' => $details['services'] ?? [],
                    'directions' => $details['directions'] ?? [],
                    'specializations_title' => $details['specializations_title'] ?? null,
                ],
            ],
            'confidence_score' => 100,
            'fetched_at' => now(),
            'status' => 'imported',
        ]);
        $dataSource->save();

        ProfileDataSource::query()
            ->where('source_type', 'top20_import')
            ->whereIn('url', $variants)
            ->where('id', '!=', $dataSource->id)
            ->delete();
    }

    private function syncImportedProfileCategories(Profile $profile, int $categoryId, ?int $subcategoryId, bool $isNew): void
    {
        $incomingRootId = CategoryHierarchy::resolveRootCategoryId($categoryId);
        if (! $incomingRootId) {
            return;
        }

        $incomingChildId = CategoryHierarchy::resolveValidSubcategoryId($incomingRootId, $subcategoryId);

        if ($isNew) {
            CategoryHierarchy::syncProfileCategories($profile, $incomingRootId, $incomingChildId);

            return;
        }

        $this->attachImportedProfileCategory($profile, $incomingRootId, $incomingChildId);
    }

    private function attachImportedProfileCategory(Profile $profile, int $rootId, ?int $childId): void
    {
        $hasPrimary = $profile->categories()
            ->wherePivot('is_primary', true)
            ->exists();
        $primaryId = $childId ?: $rootId;
        $existingCategoryIds = $profile->categories()
            ->pluck('categories.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $existingCategoryIds = array_fill_keys($existingCategoryIds, true);

        $sync = [];
        foreach (array_values(array_filter([$rootId, $childId])) as $categoryId) {
            if (isset($existingCategoryIds[$categoryId])) {
                continue;
            }

            $sync[$categoryId] = ['is_primary' => ! $hasPrimary && $primaryId === $categoryId];
        }

        if ($sync !== []) {
            $profile->categories()->syncWithoutDetaching($sync);
        }

        if (! $hasPrimary && isset($existingCategoryIds[$primaryId])) {
            $profile->categories()->updateExistingPivot($primaryId, ['is_primary' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCompanyDetails(string $top20Url, ?string $prefetchedHtml = null): array
    {
        $html = $prefetchedHtml !== null && trim($prefetchedHtml) !== ''
            ? $prefetchedHtml
            : $this->fetchHtml($top20Url);
        if ($html === null) {
            return [
                'top20_url' => $top20Url,
                'reviews' => [],
            ];
        }

        $meta = $this->parseTop20Microdata($html);
        $companyId = $this->extractTop20CompanyId($html);
        $contactData = $this->extractTop20ContactData($html);
        $visibleProfileData = $this->extractTop20VisibleProfileData($html);
        $maxReviews = $this->resolveReviewFetchLimit(
            $meta['aggregate_count'] ?? null,
            (int) config('top20_bulk_import.max_reviews_per_profile', 1000)
        );

        $reviews = [];
        if ($companyId !== null) {
            $reviews = $this->parseTop20WidgetReviews($companyId, $top20Url, $maxReviews, $meta);
        }

        if ($reviews === [] && isset($meta['reviews']) && is_array($meta['reviews'])) {
            $reviews = $this->mapTop20MicrodataReviews($meta, $top20Url, $maxReviews);
        }

        $taxonomy = $this->extractTop20Taxonomy($html, $meta);
        $descriptionFallback = $this->extractTop20MetaDescription($html);
        if ($descriptionFallback === '') {
            $descriptionFallback = (string) ($meta['description'] ?? '');
        }
        $descriptionContent = $this->extractTop20DescriptionContent($html, $descriptionFallback);

        $officialWebsite = $this->sanitizeOfficialWebsite((string) ($contactData['website'] ?? $meta['website'] ?? ''));
        $resolvedName = $this->normalizeImportedSingleLineText((string) ($visibleProfileData['name'] ?? $contactData['company_name'] ?? $meta['name'] ?? ''));
        $resolvedCity = $this->canonicalImportedCity((string) ($visibleProfileData['city'] ?? $meta['city'] ?? ''));
        $resolvedAddress = $this->localizeImportedAddress(
            (string) ($visibleProfileData['address'] ?? $contactData['addresses'][0] ?? $meta['address'] ?? ''),
            $resolvedCity
        );
        $resolvedAddresses = collect((array) ($contactData['addresses'] ?? []))
            ->prepend($resolvedAddress)
            ->map(fn ($address) => $this->localizeImportedAddress((string) $address, $resolvedCity))
            ->filter(fn ($address) => $address !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'top20_url' => $top20Url,
            'name' => $resolvedName,
            'website' => $officialWebsite,
            'phone' => trim((string) ($meta['phone'] ?? '')),
            'phones' => $contactData['phones'] !== [] ? $contactData['phones'] : array_values(array_filter([trim((string) ($meta['phone'] ?? ''))])),
            'address' => $resolvedAddress,
            'addresses' => $resolvedAddresses,
            'city' => $resolvedCity,
            'aggregate_rating' => $meta['aggregate_rating'] ?? null,
            'aggregate_count' => $meta['aggregate_count'] ?? null,
            'description' => $descriptionContent['html'],
            'description_plain' => $descriptionContent['plain'],
            'short_description' => $descriptionContent['short'],
            'avatar_image' => trim((string) ($meta['avatar_image'] ?? '')),
            'image' => trim((string) ($meta['image'] ?? '')),
            'additional_image' => trim((string) ($meta['additional_image'] ?? '')),
            'additional_images' => array_values(array_filter((array) ($meta['additional_images'] ?? []))),
            'social_links' => $this->extractTop20SocialLinks($html),
            'reviews' => $reviews,
            'services' => $taxonomy['services'],
            'directions' => $taxonomy['directions'],
            'specializations_title' => $taxonomy['specializations_title'],
        ];
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = $this->http()
                ->retry(2, 300, function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    // Не ретраїмо 500 — так Top20 відповідає на сторінки за межами останньої.
                    return $exception instanceof RequestException
                        && in_array($exception->response->status(), [408, 425, 429, 502, 503, 504], true);
                })
                ->get($url)
                ->throw();
        } catch (\Throwable) {
            return null;
        }

        $html = (string) $response->body();

        return $html !== '' ? $html : null;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<string, string>
     */
    private function fetchTop20HtmlBatch(array $urls, int $concurrency): array
    {
        $urls = array_values(array_unique(array_filter(
            array_map(fn ($url) => $this->normalizeAbsoluteUrl((string) $url), $urls),
            fn ($url) => $url !== ''
        )));

        if ($urls === []) {
            return [];
        }

        $headers = $this->top20HttpHeaders();
        $timeout = (int) config('top20_bulk_import.http_timeout', 15);
        $concurrency = max(1, min(count($urls), $concurrency));

        try {
            $responses = Http::withOptions([])->pool(
                function (Pool $pool) use ($urls, $headers, $timeout): void {
                    foreach ($urls as $url) {
                        $pool->as($url)
                            ->withHeaders($headers)
                            ->timeout($timeout)
                            ->get($url);
                    }
                },
                $concurrency
            );
        } catch (\Throwable) {
            return [];
        }

        $htmlByUrl = [];
        foreach ($urls as $url) {
            $response = $responses[$url] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $html = (string) $response->body();
            if ($html !== '') {
                $htmlByUrl[$url] = $html;
            }
        }

        return $htmlByUrl;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders($this->top20HttpHeaders())
            ->timeout((int) config('top20_bulk_import.http_timeout', 15));
    }

    /**
     * @return array<string, string>
     */
    private function top20HttpHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            'Accept-Language' => 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseListingCards(string $html, ?string $fallbackCity = null): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        $nodes = $xpath->query('//div[contains(@class, "js-company") and contains(@class, "card")]');
        if (! $nodes) {
            return [];
        }

        $cards = [];

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $name = trim((string) $xpath->evaluate('string(.//div[contains(@class,"caption-title")]//a[1])', $node));
            $url = trim((string) $xpath->evaluate('string(.//div[contains(@class,"caption-title")]//a[1]/@href)', $node));
            if ($name === '' || $url === '') {
                continue;
            }

            $ratingRaw = trim((string) $xpath->evaluate('string(.//div[contains(@class,"caption-raiting")]//div[contains(@class,"label")]//span[1])', $node));
            $reviewsText = trim((string) $xpath->evaluate('string(.//span[contains(@class,"rating-result")][1])', $node));
            $address = trim((string) $xpath->evaluate('string((.//a[contains(@class,"ga-company-address")] | .//a[contains(@class,"Click_address_category_mobile")])[1])', $node));

            $cards[] = [
                'company_id' => (int) $node->getAttribute('data-id'),
                'name' => $name,
                'url' => $this->normalizeTop20ProfileUrl($url),
                'rating' => is_numeric(str_replace(',', '.', $ratingRaw)) ? (float) str_replace(',', '.', $ratingRaw) : null,
                'reviews_count' => $this->extractFirstInt($reviewsText),
                'address' => $address !== '' ? $address : null,
                'city' => $fallbackCity,
                'teaser' => trim((string) $xpath->evaluate('string(.//div[contains(@class,"js-announce")][1])', $node)) ?: null,
                'description' => trim((string) $xpath->evaluate('string(.//div[contains(@class,"caption-description")][1])', $node)) ?: null,
            ];
        }

        return $cards;
    }

    /**
     * @return array<int, string>
     */
    private function extractPaginationUrls(string $html, string $baseUrl): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//div[contains(@class,"js-custom_pagination")]//a[@href]');

        if (! $nodes) {
            return [];
        }

        $urls = [];
        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $href = trim((string) $node->getAttribute('href'));
            $normalized = $this->normalizeAbsoluteUrl($href, $baseUrl);
            if ($normalized !== '') {
                $urls[$normalized] = $normalized;
            }
        }

        return array_values($urls);
    }

    private function extractTop20ListingPageNumber(string $url): ?int
    {
        $normalized = $this->normalizeAbsoluteUrl($url);
        if ($normalized === '') {
            return null;
        }

        $path = (string) parse_url($normalized, PHP_URL_PATH);
        if (preg_match('#/page/(\d+)/?$#i', $path, $matches)) {
            return max(1, (int) ($matches[1] ?? 1));
        }

        parse_str((string) parse_url($normalized, PHP_URL_QUERY), $query);
        if (filled($query['page'] ?? null)) {
            return max(1, (int) $query['page']);
        }

        return null;
    }

    /**
     * @return array{name:string,website:string,phone:string,address:string,city:string,aggregate_rating:float|null,aggregate_count:int|null,description:string,image:string,additional_image:string,reviews:array<int,array<string,mixed>>}|array{}
     */
    private function parseTop20Microdata(string $html): array
    {
        if (! preg_match('/<script[^>]+id=["\']company-microdata["\'][^>]*>(.*?)<\/script>/isu', $html, $m)) {
            return [];
        }

        $json = trim(html_entity_decode((string) $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $payload = json_decode($json, true);
        if (! is_array($payload)) {
            return [];
        }

        return [
            'name' => trim((string) data_get($payload, 'name', '')),
            'website' => trim((string) data_get($payload, 'url', '')),
            'phone' => trim((string) data_get($payload, 'telephone', '')),
            'address' => trim((string) data_get($payload, 'address.streetAddress', '')),
            'city' => trim((string) data_get($payload, 'address.addressLocality', '')),
            'aggregate_rating' => is_numeric(data_get($payload, 'aggregateRating.ratingValue'))
                ? (float) data_get($payload, 'aggregateRating.ratingValue')
                : null,
            'aggregate_count' => is_numeric(data_get($payload, 'aggregateRating.ratingCount'))
                ? (int) data_get($payload, 'aggregateRating.ratingCount')
                : null,
            'description' => trim((string) data_get($payload, 'description', '')),
            'avatar_image' => $this->extractTop20AvatarImage($html, $payload),
            'image' => trim((string) data_get($payload, 'image', '')),
            'additional_image' => trim((string) data_get($payload, 'additionalImage.0', '')),
            'additional_images' => array_values(array_filter(array_map(
                fn ($image) => trim((string) $image),
                (array) data_get($payload, 'additionalImage', [])
            ))),
            'offer_names' => $this->extractTop20OfferNames($payload),
            'reviews' => array_values(array_filter((array) data_get($payload, 'review', []), fn ($review) => is_array($review))),
        ];
    }

    /**
     * @return array{phones: array<int, string>, addresses: array<int, string>, website: string, company_name: string}
     */
    private function extractTop20ContactData(string $html): array
    {
        $website = $this->extractTop20OfficialWebsite($html);

        if (! preg_match('/var\s+addresses\s*=\s*(\[[\s\S]*?\]);/u', $html, $m)) {
            return ['phones' => [], 'addresses' => [], 'website' => $website, 'company_name' => ''];
        }

        $payload = json_decode((string) $m[1], true);
        if (! is_array($payload)) {
            return ['phones' => [], 'addresses' => [], 'website' => $website, 'company_name' => ''];
        }

        $phones = [];
        $addresses = [];
        $companyName = '';

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $address = trim((string) ($item['address'] ?? ''));
            if ($address !== '') {
                $addresses[$address] = $address;
            }

            if ($companyName === '') {
                $companyName = trim((string) data_get($item, 'company.name', ''));
            }

            foreach ((array) ($item['phones'] ?? []) as $phone) {
                if (! is_array($phone)) {
                    continue;
                }

                $formatted = trim((string) ($phone['phone_formatted'] ?? ''));
                $raw = trim((string) ($phone['phone_to_call'] ?? ''));
                $value = $formatted !== '' ? $formatted : $raw;
                if ($value !== '') {
                    $phones[$value] = $value;
                }
            }
        }

        return [
            'phones' => array_values($phones),
            'addresses' => array_values($addresses),
            'website' => $website,
            'company_name' => $companyName,
        ];
    }

    /**
     * @return array{name:string, city:string, address:string}
     */
    private function extractTop20VisibleProfileData(string $html): array
    {
        $name = '';
        $city = '';
        $address = '';

        if (preg_match('/var\s+addresses\s*=\s*(\[[\s\S]*?\]);/u', $html, $m)) {
            $payload = json_decode((string) $m[1], true);
            if (is_array($payload)) {
                foreach ($payload as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    if ($name === '') {
                        $name = $this->normalizeImportedSingleLineText((string) data_get($item, 'company.name', ''));
                    }

                    if ($address === '') {
                        $address = $this->normalizeImportedSingleLineText((string) ($item['address'] ?? ''));
                    }

                    if ($name !== '' && $address !== '') {
                        break;
                    }
                }
            }
        }

        if ($city === '' && preg_match("/dataLayer\\.push\\(\\{'city':\\s*'([^']+)'\\}\\);/u", $html, $m)) {
            $city = trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $title = $this->extractTop20TitleTag($html);
        if ($city === '' && $title !== '' && preg_match('/^(.*?)\s+([^\-]+?)\s*-\s*\d+/u', $title, $m)) {
            $city = trim((string) ($m[2] ?? ''));
        }

        if ($name === '') {
            $ogTitle = $this->extractTop20MetaPropertyContent($html, 'og:title');
            if ($ogTitle !== '') {
                $name = trim((string) preg_replace('/\.\s*Відгуки.*$/u', '', $ogTitle));
            }
        }

        if ($name === '' && $title !== '') {
            $candidate = trim((string) preg_replace('/\s*-\s*\d+.*$/u', '', $title));
            if ($city !== '' && preg_match('/^(.*?)(?:\s+' . preg_quote($city, '/') . ')$/u', $candidate, $m)) {
                $candidate = trim((string) ($m[1] ?? ''));
            }
            $name = $candidate;
        }

        return [
            'name' => trim($name),
            'city' => $this->canonicalImportedCity($city),
            'address' => trim($address),
        ];
    }

    private function extractTop20MetaDescription(string $html): string
    {
        return $this->extractTop20MetaNameContent($html, 'description');
    }

    private function extractTop20TitleTag(string $html): string
    {
        if (! preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $m)) {
            return '';
        }

        return trim(html_entity_decode(strip_tags((string) ($m[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractTop20MetaPropertyContent(string $html, string $property): string
    {
        $quotedProperty = preg_quote($property, '/');

        if (! preg_match('/<meta[^>]+property=["\']' . $quotedProperty . '["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m)
            && ! preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']' . $quotedProperty . '["\']/isu', $html, $m)) {
            return '';
        }

        return trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractTop20MetaNameContent(string $html, string $name): string
    {
        $quotedName = preg_quote($name, '/');

        if (! preg_match('/<meta[^>]+name=["\']' . $quotedName . '["\'][^>]+content=["\']([^"\']+)["\']/isu', $html, $m)
            && ! preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']' . $quotedName . '["\']/isu', $html, $m)) {
            return '';
        }

        return trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function extractTop20OfficialWebsite(string $html): string
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return '';
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        $queries = [
            '//a[contains(@class, "ga-company-site")]/@href',
            '//a[@data-qa-selector="company-site"]/@href',
            '//a[contains(normalize-space(.), "Перейти на сайт")]/@href',
            '//a[contains(normalize-space(.), "Перейти на сайт")]/text()',
            '//a[contains(normalize-space(.), "Перейти на сайт") or contains(normalize-space(.), "Перейти на сайт")]/ancestor::li[1]//a[@href][1]/@href',
            '//div[@id="company-show"]//a[contains(@class, "site")]/@href',
        ];

        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (! $nodes) {
                continue;
            }

            foreach ($nodes as $node) {
                $candidate = trim((string) $node->nodeValue);
                $website = $this->sanitizeOfficialWebsite($candidate);

                if ($website !== '') {
                    return $website;
                }
            }
        }

        return '';
    }

    private function extractTop20CompanyId(string $html): ?int
    {
        if (! preg_match('/id=["\']company-show["\'][^>]*data-id=["\'](\d+)["\']/isu', $html, $m)) {
            return null;
        }

        $id = (int) ($m[1] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function resolveReviewFetchLimit(?int $aggregateCount, int $configuredLimit): int
    {
        $configuredLimit = max(1, $configuredLimit);

        return min(1000, $configuredLimit);
    }

    /**
     * @param  array{name?:string,website?:string,phone?:string,aggregate_rating?:float|null,aggregate_count?:int|null}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function parseTop20WidgetReviews(int $companyId, string $top20Url, int $maxReviews, array $meta = []): array
    {
        $baseUrl = 'https://top20.ua/company-widget/' . $companyId . '/reviews';
        $mentions = [];
        $seenReviewIds = [];
        $maxPages = max(1, min(120, (int) ceil($maxReviews / 10) + 2));

        $aggregateCount = is_numeric($meta['aggregate_count'] ?? null) ? (int) $meta['aggregate_count'] : 0;
        if ($aggregateCount > 0) {
            // Віджет віддає ~10 відгуків на сторінку: не запитуємо сторінки, яких точно немає.
            $maxPages = min($maxPages, max(1, (int) ceil(min($maxReviews, $aggregateCount) / 10) + 1));
        }

        $html = $this->fetchHtml($baseUrl);
        if ($html === null) {
            return [];
        }

        $mentions = $this->appendTop20WidgetReviews($mentions, $seenReviewIds, $html, $top20Url, $meta, $maxReviews);
        if (count($mentions) >= $maxReviews) {
            return $mentions;
        }

        $nextUrl = $this->extractTop20NextPageUrl($html);
        if ($nextUrl === null || $maxPages <= 1) {
            return $mentions;
        }

        $remainingUrls = $this->buildTop20WidgetReviewPageUrls($baseUrl, $nextUrl, $maxPages);

        if ($this->reviewFetchConcurrency() > 1 && count($remainingUrls) > 1) {
            $htmlByPageUrl = $this->fetchTop20HtmlBatch($remainingUrls, $this->reviewFetchConcurrency());
            $failedUrls = [];

            foreach ($remainingUrls as $url) {
                $pageHtml = $htmlByPageUrl[$url] ?? null;
                if ($pageHtml === null) {
                    $failedUrls[] = $url;
                    continue;
                }

                $mentions = $this->appendTop20WidgetReviews($mentions, $seenReviewIds, $pageHtml, $top20Url, $meta, $maxReviews);
                if (count($mentions) >= $maxReviews) {
                    return $mentions;
                }
            }

            // Пул мовчки губить сторінки при тимчасових збоях — добираємо їх послідовно нижче.
            $remainingUrls = $failedUrls;
        }

        $queue = array_values($remainingUrls);
        $requestedUrls = [];

        while ($queue !== []) {
            $url = (string) array_shift($queue);
            if ($url === '' || isset($requestedUrls[$url])) {
                continue;
            }
            $requestedUrls[$url] = true;

            $pageHtml = $this->fetchHtml($url);
            if ($pageHtml === null) {
                continue;
            }

            $mentions = $this->appendTop20WidgetReviews($mentions, $seenReviewIds, $pageHtml, $top20Url, $meta, $maxReviews);
            if (count($mentions) >= $maxReviews) {
                break;
            }

            // Коли наперед відомих URL немає (нестандартна пагінація), ідемо за посиланням "наступна сторінка".
            if ($queue === [] && count($requestedUrls) < $maxPages - 1) {
                $followUrl = $this->extractTop20NextPageUrl($pageHtml);
                if ($followUrl !== null && ! isset($requestedUrls[$followUrl])) {
                    $queue[] = $followUrl;
                }
            }
        }

        return $mentions;
    }

    /**
     * @param  array<int, array<string, mixed>>  $mentions
     * @param  array<string, bool>  $seenReviewIds
     * @return array<int, array<string, mixed>>
     */
    private function appendTop20WidgetReviews(array $mentions, array &$seenReviewIds, string $html, string $top20Url, array $meta, int $maxReviews): array
    {
        foreach ($this->extractTop20WidgetReviewItems($html, $top20Url, $meta) as $item) {
            $rid = (string) ($item['_rid'] ?? '');
            if ($rid !== '' && isset($seenReviewIds[$rid])) {
                continue;
            }
            if ($rid !== '') {
                $seenReviewIds[$rid] = true;
            }

            $mentions[] = $item;
            if (count($mentions) >= $maxReviews) {
                break;
            }
        }

        return $mentions;
    }

    /**
     * @return array<int, string>
     */
    private function buildTop20WidgetReviewPageUrls(string $baseUrl, string $nextUrl, int $maxPages): array
    {
        $nextParts = parse_url($nextUrl);
        $baseParts = parse_url($baseUrl);
        if ($nextParts === false || $baseParts === false) {
            return [$nextUrl];
        }

        $nextPath = '/' . ltrim((string) ($nextParts['path'] ?? ''), '/');
        $basePath = '/' . ltrim((string) ($baseParts['path'] ?? ''), '/');
        parse_str((string) ($nextParts['query'] ?? ''), $query);

        if ($nextPath !== $basePath || ! isset($query['page']) || ! is_numeric($query['page'])) {
            return [$nextUrl];
        }

        $startPage = max(2, (int) $query['page']);
        $urls = [];
        for ($page = $startPage; $page <= $maxPages; $page++) {
            $urls[] = $baseUrl . '?page=' . $page;
        }

        return $urls;
    }

    /**
     * @param  array{name?:string,website?:string,phone?:string,aggregate_rating?:float|null,aggregate_count?:int|null}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function extractTop20WidgetReviewItems(string $html, string $top20Url, array $meta = []): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//div[@id="company-reviews"]//div[starts-with(@id, "review-")]');
        if (! $nodes) {
            return [];
        }

        $mentions = [];
        $aggregateRating = $meta['aggregate_rating'] ?? null;
        $aggregateCount = $meta['aggregate_count'] ?? null;

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $rid = trim((string) $node->getAttribute('id'));
            $author = trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//span[1])', $node));
            $text = $this->extractTop20ReviewTextFromNode($xpath, $node);
            $ratingRaw = trim((string) $xpath->evaluate('string((.//div[contains(@class,"company_page--top")]//div[contains(@class,"label")]//span)[1])', $node));
            $reviewDate = trim((string) $xpath->evaluate('string((.//div[contains(@class,"comment-data")])[1])', $node));
            $ratingValue = is_numeric(str_replace(',', '.', $ratingRaw)) ? (float) str_replace(',', '.', $ratingRaw) : null;
            if (! is_numeric($ratingValue)) {
                continue;
            }

            $mentions[] = [
                '_rid' => $rid,
                'source_type' => 'top20',
                'url' => $top20Url,
                'external_rating' => is_numeric($aggregateRating) ? (float) $aggregateRating : null,
                'external_reviews_count' => is_numeric($aggregateCount) ? (int) $aggregateCount : null,
                'review_author' => $author !== '' ? $author : 'Користувач',
                'review_author_avatar_url' => $this->normalizeExternalAvatarUrl((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//img/@src)', $node))
                    ?? $this->normalizeExternalAvatarUrl((string) $xpath->evaluate('string(.//div[contains(@class,"media-left")]//img/@data-src)', $node)),
                'review_text' => $text !== '' ? Str::limit($this->sanitizeTop20ReviewText($text), 4000, '') : null,
                'review_rating' => (float) $ratingValue,
                'review_date' => $reviewDate !== '' ? $reviewDate : null,
            ];
        }

        return $mentions;
    }

    private function extractTop20ReviewTextFromNode(\DOMXPath $xpath, \DOMElement $node): string
    {
        $fullText = trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-text")]//span[contains(@class,"full_review")])', $node));
        if ($fullText !== '') {
            return $fullText;
        }

        $previewText = trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-text")]//span[contains(@class,"init_review")])', $node));
        if ($previewText !== '') {
            return $previewText;
        }

        return trim((string) $xpath->evaluate('string(.//div[contains(@class,"media-text")])', $node));
    }

    private function extractTop20NextPageUrl(string $html): ?string
    {
        if (! preg_match('/class=["\'][^"\']*js-more[^"\']*["\'][^>]*data-href=["\']([^"\']+)["\']/isu', $html, $m)) {
            return null;
        }

        $encoded = html_entity_decode(trim((string) ($m[1] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        return $this->normalizeAbsoluteUrl($decoded);
    }

    /**
     * @param  array{name?:string,website?:string,phone?:string,aggregate_rating?:float|null,aggregate_count?:int|null,reviews?:array<int,array<string,mixed>>}  $meta
     * @return array<int, array<string, mixed>>
     */
    private function mapTop20MicrodataReviews(array $meta, string $top20Url, int $maxReviews): array
    {
        $reviews = array_values(array_filter((array) ($meta['reviews'] ?? []), fn ($review) => is_array($review)));
        if ($reviews === []) {
            return [];
        }

        $mentions = [];
        foreach (array_slice($reviews, 0, $maxReviews) as $review) {
            $rating = data_get($review, 'reviewRating.ratingValue');
            if (! is_numeric($rating)) {
                continue;
            }

            $body = $this->sanitizeTop20ReviewText((string) data_get($review, 'reviewBody', ''));
            $author = trim((string) data_get($review, 'author.name', ''));
            $mentions[] = [
                'source_type' => 'top20',
                'url' => $top20Url,
                'review_author' => $author !== '' ? $author : 'Користувач',
                'review_author_avatar_url' => null,
                'review_text' => $body !== '' ? Str::limit($body, 4000, '') : null,
                'review_rating' => (float) $rating,
                'review_date' => trim((string) data_get($review, 'datePublished', '')) ?: null,
            ];
        }

        return $mentions;
    }

    /**
     * @return array{html:string,plain:string,short:string}
     */
    private function extractTop20DescriptionContent(string $html, string $fallbackDescription = ''): array
    {
        $fallbackPlain = trim(html_entity_decode(strip_tags($fallbackDescription), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return $this->fallbackTop20DescriptionContent($fallbackPlain);
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        $descriptionNode = $xpath->query('//*[@id="company_full_description"]')->item(0)
            ?: $xpath->query('//*[@id="company_short_description"]')->item(0);

        if (! $descriptionNode instanceof \DOMElement) {
            return $this->fallbackTop20DescriptionContent($fallbackPlain);
        }

        $blocks = [];
        $plainParts = [];
        $summaryParts = [];
        $seenHashes = [];
        $encounteredHeading = false;

        foreach ($xpath->query('.//*[self::p or self::h2 or self::h3 or self::h4 or self::h5 or self::h6 or self::ul or self::ol or self::blockquote]', $descriptionNode) ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $class = mb_strtolower(trim((string) $node->getAttribute('class')));
            if (str_contains($class, 'bg-show') || str_contains($class, 'panel') || str_contains($class, 'town')) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');
            if ($text === '' || str_contains($text, 'Читати далі') || str_contains($text, 'Это ваша компания?') || str_contains($text, 'Це ваша компанія?')) {
                continue;
            }

            $hash = md5($node->tagName . '|' . $text);
            if (isset($seenHashes[$hash])) {
                continue;
            }
            $seenHashes[$hash] = true;

            $sanitizedBlock = $this->sanitizeTop20DescriptionBlock($node);
            if ($sanitizedBlock === '') {
                continue;
            }

            $blocks[] = $sanitizedBlock;
            $plainParts[] = $text;

             if (in_array(strtolower($node->tagName), ['h2', 'h3', 'h4', 'h5', 'h6'], true)) {
                $encounteredHeading = true;
            } elseif (! $encounteredHeading && strtolower($node->tagName) === 'p' && count($summaryParts) < 2) {
                $summaryParts[] = $text;
            }
        }

        $descriptionHtml = implode("\n", $blocks);
        $descriptionPlain = trim(implode("\n\n", $plainParts));
        $shortDescription = trim(implode(' ', $summaryParts));

        if ($descriptionHtml === '' || $descriptionPlain === '') {
            return $this->fallbackTop20DescriptionContent($fallbackPlain !== '' ? $fallbackPlain : $descriptionPlain);
        }

        return [
            'html' => $descriptionHtml,
            'plain' => $descriptionPlain,
            'short' => Str::limit($shortDescription !== '' ? $shortDescription : $descriptionPlain, 280, '...'),
        ];
    }

    /**
     * @return array{html:string,plain:string,short:string}
     */
    private function fallbackTop20DescriptionContent(string $plainText): array
    {
        $plainText = trim($plainText);
        if ($plainText === '') {
            return ['html' => '', 'plain' => '', 'short' => ''];
        }

        $normalizedText = preg_replace('/\r\n?/', "\n", $plainText) ?: $plainText;
        $paragraphs = preg_split('/\n{2,}/u', $normalizedText) ?: [];
        $paragraphs = array_values(array_filter(array_map(
            static fn (string $paragraph): string => trim(preg_replace('/\s+/u', ' ', $paragraph) ?: $paragraph),
            $paragraphs
        )));

        if ($paragraphs === []) {
            $paragraphs = [$plainText];
        }

        $html = collect($paragraphs)
            ->map(fn (string $paragraph) => '<p>' . e($paragraph) . '</p>')
            ->implode("\n");

        return [
            'html' => $html,
            'plain' => trim(implode("\n\n", $paragraphs)),
            'short' => Str::limit(trim(implode(' ', $paragraphs)), 280, '...'),
        ];
    }

    private function sanitizeTop20DescriptionBlock(\DOMElement $node): string
    {
        $allowedTags = ['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'strong', 'b', 'em', 'i', 'br', 'a'];
        if (! in_array(strtolower($node->tagName), $allowedTags, true)) {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $imported = $doc->importNode($node, true);
        if (! $imported instanceof \DOMElement) {
            return '';
        }

        $doc->appendChild($imported);
        $this->sanitizeTop20DescriptionNode($imported, $allowedTags);

        return trim($doc->saveHTML($imported) ?: '');
    }

    /**
     * @param  array<int, string>  $allowedTags
     */
    private function sanitizeTop20DescriptionNode(\DOMNode $node, array $allowedTags): void
    {
        for ($child = $node->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;

            if ($child instanceof \DOMText) {
                $child->nodeValue = preg_replace('/\s+/u', ' ', $child->nodeValue ?? '') ?: $child->nodeValue;
                continue;
            }

            if (! $child instanceof \DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tagName = strtolower($child->tagName);
            if (! in_array($tagName, $allowedTags, true)) {
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $href = '';
            if ($tagName === 'a') {
                $href = trim((string) $child->getAttribute('href'));
            }

            while ($child->attributes->length > 0) {
                $child->removeAttributeNode($child->attributes->item(0));
            }

            if ($tagName === 'a') {
                $href = $href !== '' ? $this->normalizeAbsoluteUrl($href) : '';
                if ($href === '' || str_starts_with(strtolower($href), 'javascript:')) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                $child->setAttribute('href', $href);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            $this->sanitizeTop20DescriptionNode($child, $allowedTags);

            $text = trim(preg_replace('/\s+/u', ' ', $child->textContent) ?: '');
            if ($text === '' && ! in_array($tagName, ['br'], true)) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * @return array{services: array<int, array{name:string,description:string,category:string,price_from:string,duration:string,cta:string}>, directions: array<int, string>, specializations_title: ?string}
     */
    private function extractTop20Taxonomy(string $html, array $meta = []): array
    {
        $taggedSections = $this->extractTop20TaggedSections($html);
        $descriptionServices = $this->extractTop20DescriptionServices($html);
        $microdataOffers = collect((array) ($meta['offer_names'] ?? []))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique(fn (string $item) => mb_strtolower($item))
            ->values();

        $services = collect();
        $directions = collect();
        $specializationsTitle = null;

        foreach ($taggedSections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $items = collect((array) ($section['items'] ?? []))
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->values();

            if ($items->isEmpty()) {
                continue;
            }

            if ($this->isTop20ServicesSection($title)) {
                $services = $services->merge(
                    $items->map(fn (string $item) => $this->buildImportedServiceCard($item, $title))
                );
                continue;
            }

            if ($this->isTop20DirectionsSection($title) && ($specializationsTitle === null || $this->isExplicitTop20SpecializationSection($title))) {
                $specializationsTitle = $title;
            }

            $directions = $directions->merge($items);
        }

        if ($descriptionServices->isNotEmpty()) {
            $services = $services->merge(
                $descriptionServices->map(fn (string $item) => $this->buildImportedServiceCard($item, 'Послуги'))
            );
        }

        if ($services->isEmpty() && $directions->isEmpty() && $microdataOffers->isNotEmpty()) {
            $services = $microdataOffers->map(
                fn (string $item) => $this->buildImportedServiceCard($item, 'Послуги')
            );
        }

        $services = $services
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->unique(fn ($item) => mb_strtolower(trim((string) ($item['name'] ?? ''))))
            ->values();

        $directions = $directions
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->reject(fn (string $item) => $services->contains(
                fn (array $service) => mb_strtolower((string) ($service['name'] ?? '')) === mb_strtolower($item)
            ))
            ->unique(fn (string $item) => mb_strtolower($item))
            ->values();

        return [
            'services' => $services->all(),
            'directions' => $directions->all(),
            'specializations_title' => $specializationsTitle,
        ];
    }

    /**
     * @return array<int, array{title:string,items:array<int, string>}>
     */
    private function extractTop20TaggedSections(string $html): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//div[contains(@class, "company_page-servise")]');
        if (! $nodes) {
            return [];
        }

        $sections = [];

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            foreach ($xpath->query('.//*[contains(@class,"h6")]', $node) ?: [] as $heading) {
                if (! $heading instanceof \DOMElement) {
                    continue;
                }

                $title = trim((string) $heading->textContent);
                if ($title === '') {
                    continue;
                }

                $nav = null;
                for ($sibling = $heading->nextSibling; $sibling !== null; $sibling = $sibling->nextSibling) {
                    if ($sibling instanceof \DOMText && trim((string) $sibling->textContent) === '') {
                        continue;
                    }

                    if (! $sibling instanceof \DOMElement) {
                        continue;
                    }

                    $class = trim((string) $sibling->getAttribute('class'));
                    if (str_contains($class, 'page-servise-nav')) {
                        $nav = $sibling;
                    }

                    break;
                }

                if (! $nav instanceof \DOMElement) {
                    continue;
                }

                $items = [];
                foreach ($xpath->query('.//a', $nav) ?: [] as $link) {
                    if (! $link instanceof \DOMElement) {
                        continue;
                    }

                    $text = trim((string) $link->textContent);
                    if ($text !== '') {
                        $items[] = $text;
                    }
                }

                if ($items === []) {
                    continue;
                }

                $sections[] = [
                    'title' => $title,
                    'items' => array_values(array_unique($items)),
                ];
            }
        }

        return $sections;
    }

    /**
     * @return Collection<int, string>
     */
    private function extractTop20DescriptionServices(string $html): Collection
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return collect();
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);
        $descriptionNode = $xpath->query('//*[@id="company_full_description" or @id="company_short_description"]')->item(0);

        if (! $descriptionNode instanceof \DOMElement) {
            return collect();
        }

        $items = [];

        foreach ($xpath->query('.//*[self::h2 or self::h3 or self::h4 or self::h5 or self::h6]', $descriptionNode) ?: [] as $heading) {
            if (! $heading instanceof \DOMElement) {
                continue;
            }

            $title = trim((string) $heading->textContent);
            if (! $this->isTop20ServicesSection($title)) {
                continue;
            }

            $next = $heading->nextSibling;
            while ($next) {
                if ($next instanceof \DOMText && trim($next->textContent) === '') {
                    $next = $next->nextSibling;
                    continue;
                }

                if ($next instanceof \DOMElement && preg_match('/^h[1-6]$/i', $next->tagName)) {
                    break;
                }

                if ($next instanceof \DOMElement && strtolower($next->tagName) === 'ul') {
                    foreach ($xpath->query('.//li', $next) ?: [] as $li) {
                        if (! $li instanceof \DOMElement) {
                            continue;
                        }

                        $text = trim((string) $li->textContent);
                        if ($text !== '') {
                            $items[] = $text;
                        }
                    }

                    break;
                }

                $next = $next->nextSibling;
            }
        }

        return collect($items)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique(fn ($item) => mb_strtolower($item))
            ->values();
    }

    private function isTop20ServicesSection(string $title): bool
    {
        $normalized = mb_strtolower(trim($title));

        return str_contains($normalized, 'послуг') || str_contains($normalized, 'послуги');
    }

    private function isTop20DirectionsSection(string $title): bool
    {
        $normalized = mb_strtolower(trim($title));

        return str_contains($normalized, 'спеціал')
            || str_contains($normalized, 'специал')
            || str_contains($normalized, 'фахів')
            || str_contains($normalized, 'специалист');
    }

    private function isExplicitTop20SpecializationSection(string $title): bool
    {
        $normalized = mb_strtolower(trim($title));

        return str_contains($normalized, 'спеціал') || str_contains($normalized, 'специал');
    }

    /**
     * @return array{name:string,description:string,category:string,price_from:string,duration:string,cta:string}
     */
    private function buildImportedServiceCard(string $name, string $categoryTitle = ''): array
    {
        return [
            'name' => trim($name),
            'description' => '',
            'category' => trim($categoryTitle),
            'price_from' => '',
            'duration' => '',
            'cta' => 'Залишити заявку',
        ];
    }

    private function syncImportedTop20Content(Profile $profile, array $details): void
    {
        $services = collect((array) ($details['services'] ?? []))
            ->filter(fn ($item) => is_array($item) && filled($item['name'] ?? null))
            ->values()
            ->all();
        $specializationsTitle = trim((string) ($details['specializations_title'] ?? ''));

        $suggested = (array) ($profile->ai_suggested_data ?? []);

        if ($services !== []) {
            $suggested['profile_services'] = $services;
        }

        if ($specializationsTitle !== '') {
            $suggested['specializations_title'] = $specializationsTitle;
        }

        if ($suggested !== (array) ($profile->ai_suggested_data ?? [])) {
            $profile->ai_suggested_data = $suggested;
        }
    }

    private function syncImportedDirections(Profile $profile, Category $category, array $details): void
    {
        $directionNames = collect((array) ($details['directions'] ?? []))
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->unique(fn ($item) => mb_strtolower($item))
            ->values();

        if ($directionNames->isEmpty()) {
            return;
        }

        $existingIds = $profile->services()->pluck('category_services.id')->map(fn ($id) => (int) $id)->all();
        $knownServices = CategoryService::query()
            ->where('category_id', $category->id)
            ->get(['id', 'name', 'slug']);

        $resolvedIds = collect($existingIds);

        foreach ($directionNames as $directionName) {
            $known = $knownServices->first(
                fn (CategoryService $service) => mb_strtolower((string) $service->name) === mb_strtolower($directionName)
            );

            if (! $known) {
                $known = CategoryService::query()->create([
                    'category_id' => $category->id,
                    'name' => $directionName,
                    'slug' => $this->makeUniqueCategoryServiceSlug($category->id, $directionName),
                    'is_active' => true,
                    'show_in_catalog' => true,
                ]);

                $knownServices->push($known);
            }

            $resolvedIds->push((int) $known->id);
        }

        $profile->services()->sync($resolvedIds->unique()->values()->all());
    }

    private function resolveRegionId(string $regionName): ?int
    {
        $regionName = trim($regionName);
        if ($regionName === '') {
            return null;
        }

        return Region::query()
            ->where('slug', Str::slug($regionName))
            ->value('id');
    }

    private function resolveImportRegionId(string $regionName, string $cityName, ?int $fallbackRegionId = null): ?int
    {
        $regionId = $this->resolveRegionId($regionName);
        if ($regionId) {
            return $regionId;
        }

        $cityName = $this->canonicalImportedCity($cityName);
        if ($cityName === '') {
            return $fallbackRegionId;
        }

        $regions = Region::query()->select(['id', 'name'])->get();
        $inferred = RegionCityDirectory::inferRegionId($cityName, $regions);

        return $inferred ?: $fallbackRegionId;
    }

    private function canonicalImportedCity(string $city): string
    {
        $city = trim($city);
        if ($city === '') {
            return '';
        }

        return RegionCityDirectory::canonicalCity($city) ?? $city;
    }

    private function localizeImportedAddress(string $address, string $city): string
    {
        $address = trim($address);
        if ($address === '') {
            return '';
        }

        $city = $this->canonicalImportedCity($city);
        if ($city !== '') {
            $aliases = RegionCityDirectory::variantsForCity($city);
            usort($aliases, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));

            foreach ($aliases as $alias) {
                if ($alias === '' || $alias === $city) {
                    continue;
                }

                $address = preg_replace('/\b' . preg_quote($alias, '/') . '\b/u', $city, $address) ?: $address;
            }
        }

        return $this->normalizeImportedSingleLineText($address);
    }

    private function normalizeImportedSingleLineText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;

        return trim($value);
    }

    private function uniqueProfileSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'profile-' . Str::lower(Str::random(6));
        $slug = $base;
        $counter = 2;

        while (Profile::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function persistImportedProfile(Profile $profile): void
    {
        if ($profile->exists) {
            $profile->save();

            return;
        }

        $slugSeed = (string) ($profile->name ?: 'Профіль');
        $attempt = 0;

        while (true) {
            try {
                $profile->save();

                return;
            } catch (QueryException $e) {
                if (! $this->isDuplicateSlugException($e) || $attempt >= 6) {
                    throw $e;
                }

                $attempt++;
                $profile->slug = $this->uniqueProfileSlug($slugSeed);
            }
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function runTransactionWithRetry(callable $callback, int $attempts = 3): mixed
    {
        $maxAttempts = max(1, $attempts);

        beginning:
        try {
            return DB::transaction($callback);
        } catch (\Throwable $e) {
            if ($maxAttempts <= 1 || ! $this->isRetryableDatabaseException($e)) {
                throw $e;
            }

            $maxAttempts--;
            usleep(250000);

            goto beginning;
        }
    }

    private function isDuplicateSlugException(\Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $message = Str::lower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            && (str_contains($message, 'profiles_slug_unique') || str_contains($message, '`slug`'));
    }

    private function isDuplicateProfileCategoryException(\Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $message = Str::lower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            && str_contains($message, 'profile_category')
            && str_contains($message, 'profile_id_category_id');
    }

    private function isRetryableDatabaseException(\Throwable $e): bool
    {
        $message = Str::lower($e->getMessage());

        return $this->isDuplicateSlugException($e)
            || $this->isDuplicateProfileCategoryException($e)
            || str_contains($message, 'lock wait timeout exceeded')
            || str_contains($message, 'deadlock found')
            || str_contains($message, 'database is locked');
    }

    private function normalizeAbsoluteUrl(string $url, ?string $baseUrl = null): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        if ($baseUrl && preg_match('#^https?://#i', $baseUrl)) {
            $base = parse_url($baseUrl);
            if ($base !== false) {
                $scheme = (string) ($base['scheme'] ?? 'https');
                $host = (string) ($base['host'] ?? '');
                if ($host !== '') {
                    if (str_starts_with($url, '/')) {
                        return $scheme . '://' . $host . $url;
                    }

                    $path = (string) ($base['path'] ?? '/');
                    $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');

                    return $scheme . '://' . $host . ($dir !== '' ? $dir . '/' : '/') . ltrim($url, '/');
                }
            }
        }

        if (str_starts_with($url, '/')) {
            return 'https://top20.ua' . $url;
        }

        return 'https://top20.ua/' . ltrim($url, '/');
    }

    private function normalizeTop20ProfileUrl(string $url): string
    {
        $absoluteUrl = $this->normalizeAbsoluteUrl($url);
        if ($absoluteUrl === '') {
            return '';
        }

        $parts = parse_url($absoluteUrl);
        if ($parts === false) {
            return rtrim($absoluteUrl, '/');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        if ($host === 'top20.ua' || str_ends_with($host, '.top20.ua')) {
            $path = preg_replace('#^/ru(?=/|$)#i', '', $path) ?: '/';

            return 'https://top20.ua' . ($path === '/' ? '' : rtrim($path, '/'));
        }

        return rtrim($absoluteUrl, '/');
    }

    /**
     * @return array<int, string>
     */
    private function top20ProfileUrlVariants(string $url): array
    {
        $absoluteUrl = $this->normalizeAbsoluteUrl($url);
        if ($absoluteUrl === '') {
            return [];
        }

        $canonicalUrl = $this->normalizeTop20ProfileUrl($absoluteUrl);
        $variants = [
            rtrim($absoluteUrl, '/'),
            $canonicalUrl,
        ];

        $parts = parse_url($canonicalUrl);
        $path = (string) ($parts['path'] ?? '');

        if ($path !== '') {
            $variants[] = 'https://top20.ua/ru' . $path;
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function normalizeWebsite(string $website): ?string
    {
        if (blank($website)) {
            return null;
        }

        return Str::of($website)
            ->replace(['https://', 'http://', 'www.'], '')
            ->trim('/')
            ->lower()
            ->toString();
    }

    /**
     * @param  array<int, mixed>  $images
     * @return array<int, string>
     */
    private function normalizeImportedGallery(array $images): array
    {
        return array_values(array_filter(array_unique(array_map(
            fn ($image) => trim((string) $image),
            $images
        ))));
    }

    /**
     * @param  array<string, mixed>  $socialLinks
     * @return array<string, string>
     */
    private function normalizeImportedSocialLinks(array $socialLinks): array
    {
        $normalized = [];

        foreach ($socialLinks as $network => $url) {
            $key = trim((string) $network);
            $value = trim((string) $url);

            if ($key === '' || $value === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function sanitizeOfficialWebsite(string $website): string
    {
        $website = trim($website);

        if ($website === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $website) && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s]*)?$/iu', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }

        $normalized = $this->normalizeAbsoluteUrl($website);
        $parts = parse_url($normalized);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return '';
        }

        if ($host === 'top20.ua' || str_ends_with($host, '.top20.ua')) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) ?: 'https';
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '/' ? '' : rtrim($path, '/');

        return $scheme . '://' . $host . $path;
    }

    private function isTop20PlaceholderWebsite(string $website): bool
    {
        $normalized = $this->normalizeAbsoluteUrl($website);
        if ($normalized === '') {
            return false;
        }

        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));

        return $host === 'top20.ua' || str_ends_with($host, '.top20.ua');
    }

    private function normalizePhoneDigits(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';

        return $digits !== '' ? $digits : null;
    }

    private function normalizeExternalMentionUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return Str::lower($url);
        }

        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');
        $fragment = (string) ($parts['fragment'] ?? '');

        $normalized = 'https://' . $host . rtrim($path, '/');
        if ($query !== '') {
            $normalized .= '?' . $query;
        }
        if ($fragment !== '') {
            $normalized .= '#' . $fragment;
        }

        return $normalized;
    }

    private function normalizeExternalAvatarUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if ($this->isPlaceholderImageUrl($url)) {
            return null;
        }

        $generatedPath = GeneratedProfileLogo::normalizePath($url);
        if ($generatedPath !== null) {
            return $generatedPath;
        }

        $localPath = $this->normalizeStoredMediaPath($url);
        if ($localPath !== null) {
            return $this->relocateLegacyImportedMediaPath($localPath);
        }

        if (! preg_match('#^https?://#i', $url) && ! str_starts_with($url, '//')) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function localizeImportedImageReference(string $url, string $bucket, string $seed, ?int $maxBytes = null): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if ($this->isPlaceholderImageUrl($url)) {
            return null;
        }

        $generatedPath = GeneratedProfileLogo::normalizePath($url);
        if ($generatedPath !== null) {
            return $generatedPath;
        }

        $localPath = $this->normalizeStoredMediaPath($url);
        if ($localPath !== null) {
            return $this->relocateLegacyImportedMediaPath($localPath);
        }

        $absoluteUrl = $this->normalizeAbsoluteUrl($url);
        if ($absoluteUrl === '') {
            return null;
        }

        $cacheKey = $bucket . '|' . $absoluteUrl;
        if (array_key_exists($cacheKey, $this->storedMediaCache)) {
            return $this->storedMediaCache[$cacheKey] ?: $absoluteUrl;
        }

        $storedPath = $this->downloadImportedImageToDisk(
            $absoluteUrl,
            $bucket,
            $seed,
            $maxBytes ?? (int) config('top20_bulk_import.media_max_bytes', 6 * 1024 * 1024)
        );

        if (! $storedPath && $this->isTop20HostedMediaUrl($absoluteUrl)) {
            $this->storedMediaCache[$cacheKey] = null;

            return null;
        }

        $this->storedMediaCache[$cacheKey] = $storedPath;

        return $storedPath ?: $absoluteUrl;
    }

    private function isTop20HostedMediaUrl(string $url): bool
    {
        $absoluteUrl = $this->normalizeAbsoluteUrl($url);
        if ($absoluteUrl === '') {
            return false;
        }

        $host = strtolower((string) parse_url($absoluteUrl, PHP_URL_HOST));
        $path = strtolower((string) parse_url($absoluteUrl, PHP_URL_PATH));

        return ($host === 'top20.ua' || str_ends_with($host, '.top20.ua'))
            && (
                str_starts_with($path, '/media-resize/')
                || str_starts_with($path, '/media-resize-url/')
                || str_starts_with($path, '/media/')
            );
    }

    private function isPlaceholderImageUrl(string $url): bool
    {
        if (MediaUrl::isKnownPlaceholderPath($url)) {
            return true;
        }

        $path = strtolower(trim((string) parse_url($url, PHP_URL_PATH), '/'));
        if ($path === '') {
            $path = strtolower(trim(strtok($url, '?') ?: $url, '/'));
        }

        return $path === 'img/empty.png'
            || str_ends_with($path, '/img/empty.png')
            || str_ends_with($path, '/empty.png');
    }

    private function normalizeStoredMediaPath(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (MediaUrl::isKnownPlaceholderPath($value) || $this->isPlaceholderImageUrl($value)) {
            return null;
        }

        if (preg_match('#^https?://#i', $value) || str_starts_with($value, '//')) {
            return null;
        }

        if (Str::startsWith($value, ['/storage/', 'storage/'])) {
            return ltrim((string) preg_replace('#^/?storage/#', '', $value), '/');
        }

        if (Str::startsWith($value, ['/media/', 'media/'])) {
            return ltrim((string) preg_replace('#^/?media/#', '', $value), '/');
        }

        $normalizedPath = ltrim((string) (parse_url($value, PHP_URL_PATH) ?: $value), '/');
        if (! preg_match('/\.(jpe?g|png|webp|gif|bmp|svg)$/i', $normalizedPath)) {
            return null;
        }

        $knownPrefixes = array_filter([
            trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/') . '/',
            'profiles/',
            'profile-logos/',
            'profiles-gallery/',
            'reviews-media/',
            'categories/',
            'top20/',
        ]);

        if (Str::startsWith($normalizedPath, $knownPrefixes) || Storage::disk((string) config('top20_bulk_import.media_disk', 'public'))->exists($normalizedPath)) {
            return $normalizedPath;
        }

        return null;
    }

    private function downloadImportedImageToDisk(string $url, string $bucket, string $seed, int $maxBytes): ?string
    {
        $disk = (string) config('top20_bulk_import.media_disk', 'public');
        $baseDirectory = trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/');

        try {
            $response = Http::timeout((int) config('top20_bulk_import.media_timeout', 20))
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 DOVIRA Top20 media fetcher',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                    'Referer' => 'https://top20.ua/',
                ])
                // Top20 тротлить завантаження медіа при масовому імпорті (429/503/обриви).
                // Без ретраю це раніше призводило до масової втрати аватарок у пікові дні.
                ->retry(3, 500, function (\Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && in_array($exception->response->status(), [408, 425, 429, 500, 502, 503, 504], true);
                }, throw: false)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $content = $response->body();
        if ($content === '') {
            return null;
        }

        $contentLength = strlen($content);
        if ($contentLength < 128 || $contentLength > max(1024, $maxBytes)) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($content);
        if (! is_array($imageInfo)) {
            return null;
        }

        $mime = Str::lower((string) ($imageInfo['mime'] ?? ''));
        $extension = match ($mime) {
            'image/jpeg', 'image/pjpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp', 'image/x-ms-bmp' => 'bmp',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        $hash = sha1($url);
        $slug = Str::slug(Str::ascii($seed)) ?: 'asset';
        $path = trim($baseDirectory . '/' . trim($bucket, '/') . '/' . substr($hash, 0, 2) . '/' . $slug . '-' . substr($hash, 0, 20) . '.' . $extension, '/');

        if (Storage::disk($disk)->exists($path)) {
            return $path;
        }

        return Storage::disk($disk)->put($path, $content) ? $path : null;
    }

    private function relocateLegacyImportedMediaPath(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '') {
            return $path;
        }

        $disk = (string) config('top20_bulk_import.media_disk', 'public');
        $baseDirectory = trim((string) config('top20_bulk_import.media_directory', 'catalog-media'), '/');

        if ($baseDirectory === '' || ! Str::startsWith($path, 'top20/')) {
            return $path;
        }

        $suffix = ltrim(Str::after($path, 'top20/'), '/');
        if ($suffix === '') {
            return $path;
        }

        $targetPath = trim($baseDirectory . '/' . $suffix, '/');
        if ($targetPath === $path) {
            return $path;
        }

        $storage = Storage::disk($disk);

        if ($storage->exists($targetPath)) {
            if ($storage->exists($path)) {
                $storage->delete($path);
            }

            return $targetPath;
        }

        if (! $storage->exists($path)) {
            return $path;
        }

        if ($storage->move($path, $targetPath)) {
            return $targetPath;
        }

        if ($storage->copy($path, $targetPath)) {
            $storage->delete($path);

            return $targetPath;
        }

        return $path;
    }

    private function normalizeExternalReviewDate(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        $raw = trim(html_entity_decode(strip_tags((string) $date), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $raw = preg_replace('/\s+/u', ' ', $raw) ?: $raw;

        if ($raw === '') {
            return null;
        }

        $lower = mb_strtolower($raw);
        if (in_array($lower, ['сьогодні', 'сегодня'], true)) {
            return now()->toDateString();
        }

        if (in_array($lower, ['вчора', 'вчера'], true)) {
            return now()->subDay()->toDateString();
        }

        if (preg_match('/^(?<day>\d{1,2})\s+(?<month>[[:alpha:]\pL]+)\s+(?<year>\d{4})$/u', $lower, $matches)) {
            $monthMap = [
                'січня' => 1,
                'января' => 1,
                'лютого' => 2,
                'февраля' => 2,
                'березня' => 3,
                'марта' => 3,
                'квітня' => 4,
                'апреля' => 4,
                'травня' => 5,
                'мая' => 5,
                'червня' => 6,
                'июня' => 6,
                'липня' => 7,
                'июля' => 7,
                'серпня' => 8,
                'августа' => 8,
                'вересня' => 9,
                'сентября' => 9,
                'жовтня' => 10,
                'октября' => 10,
                'листопада' => 11,
                'ноября' => 11,
                'грудня' => 12,
                'декабря' => 12,
            ];

            $month = $monthMap[$matches['month']] ?? null;
            if ($month !== null) {
                try {
                    return Carbon::createFromDate((int) $matches['year'], $month, (int) $matches['day'])->toDateString();
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $reviewLike
     */
    private function buildExternalReviewHash(array $reviewLike): string
    {
        $normalizedUrl = $this->normalizeExternalMentionUrl((string) ($reviewLike['url'] ?? ''));
        $normalizedSourceType = Str::lower(trim((string) ($reviewLike['source_type'] ?? 'external_review')));
        $normalizedAuthor = Str::lower(trim((string) ($reviewLike['review_author'] ?? '')));
        $normalizedRating = is_numeric($reviewLike['review_rating'] ?? null)
            ? (string) max(1, min(5, (int) round((float) $reviewLike['review_rating'])))
            : '';
        $normalizedDate = (string) ($this->normalizeExternalReviewDate($reviewLike['review_date'] ?? null) ?? '');
        $normalizedBody = Str::lower(trim((string) ($reviewLike['review_text'] ?? '')));
        $normalizedBody = preg_replace('/\s+/u', ' ', $normalizedBody) ?: $normalizedBody;
        $bodyOrDate = $normalizedBody !== '' ? $normalizedBody : $normalizedDate;

        return sha1(implode('|', [
            $normalizedUrl,
            $normalizedSourceType,
            $normalizedAuthor,
            $normalizedRating,
            $bodyOrDate,
        ]));
    }

    private function buildReviewSourceUrl(string $companyUrl, string $rid): string
    {
        if ($rid !== '' && preg_match('/(\d+)$/', $rid, $m)) {
            return rtrim($companyUrl, '/') . '#company-review-' . $m[1];
        }

        return $companyUrl;
    }

    private function extractDistrict(string $address): ?string
    {
        if (preg_match('/район\s+([^,]+)/iu', $address, $m)) {
            return trim((string) ($m[1] ?? '')) ?: null;
        }

        return null;
    }

    private function extractFirstInt(string $text): int
    {
        if (! preg_match('/(\d[\d\s]*)/u', $text, $m)) {
            return 0;
        }

        return (int) preg_replace('/\D+/', '', (string) ($m[1] ?? ''));
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function makeUniqueCategoryServiceSlug(int $categoryId, string $serviceName): string
    {
        $baseSlug = Str::slug($serviceName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'service';
        $slug = $baseSlug;
        $counter = 2;

        while (CategoryService::query()->where('category_id', $categoryId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function sanitizeTop20ReviewText(string $text): string
    {
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return '';
        }

        $text = str_replace("\xC2\xA0", ' ', $text);
        $markerPattern = '/(?:Показати|Показать)\s+(?:повністю|полностью)/iu';
        $parts = preg_split($markerPattern, $text, 2);

        if (is_array($parts) && count($parts) === 2) {
            $preview = trim((string) $parts[0]);
            $full = trim((string) $parts[1]);
            $normalizedPreview = trim((string) preg_replace('/[\s\.\,\!\?\:\;…]+$/u', '', $preview));

            if ($full !== '' && $normalizedPreview !== '') {
                $previewComparable = mb_strtolower($normalizedPreview);
                $fullComparable = mb_strtolower($full);

                if (Str::startsWith($fullComparable, $previewComparable)) {
                    $text = $full;
                } else {
                    $text = trim($preview . ' ' . $full);
                }
            } elseif ($full !== '') {
                $text = $full;
            } elseif ($preview !== '') {
                $text = $preview;
            }
        }

        $text = preg_replace('/(?:\s|&nbsp;|\x{00A0})*(?:Показати|Показать)\s+(?:повністю|полностью)(?:\s|&nbsp;|\x{00A0})*/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function extractTop20OgImage(string $html): string
    {
        if (! preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/iu', $html, $m)) {
            return '';
        }

        return trim(html_entity_decode((string) ($m[1] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractTop20AvatarImage(string $html, array $payload = []): string
    {
        $candidates = [
            $this->extractTop20CompanyMainPhoto($html),
            trim((string) data_get($payload, 'image', '')),
            $this->extractTop20OgImage($html),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($this->isRealTop20ProfileImage($candidate)) {
                return $this->normalizeAbsoluteUrl($candidate);
            }
        }

        return '';
    }

    private function extractTop20CompanyMainPhoto(string $html): string
    {
        if (preg_match_all('#(?:data-src|src)=["\']([^"\']*/media-resize/company_mainphoto/[^"\']+)["\']#iu', $html, $matches)) {
            foreach ($matches[1] ?? [] as $match) {
                $candidate = $this->normalizeAbsoluteUrl((string) $match);
                if ($this->isRealTop20ProfileImage($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function isRealTop20ProfileImage(string $url): bool
    {
        $normalized = $this->normalizeAbsoluteUrl($url);
        if ($normalized === '') {
            return false;
        }

        $host = strtolower((string) parse_url($normalized, PHP_URL_HOST));
        $path = strtolower((string) parse_url($normalized, PHP_URL_PATH));

        if ($host !== 'top20.ua' && ! str_ends_with($host, '.top20.ua')) {
            return false;
        }

        if (str_contains($path, '/img/top-logo-og-2.') || str_contains($path, 'logo20ua_fake')) {
            return false;
        }

        return str_contains($path, '/media-resize/company_square/')
            || str_contains($path, '/media-resize/company_mainphoto/')
            || str_contains($path, '/media-resize/company_large/');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function extractTop20OfferNames(array $payload): array
    {
        $offers = array_merge(
            (array) data_get($payload, 'makesOffer', []),
            (array) data_get($payload, 'address.makesOffer', [])
        );

        return array_values(array_filter(array_unique(array_map(function ($offer): string {
            if (! is_array($offer)) {
                return '';
            }

            return trim((string) data_get($offer, 'itemOffered.name', ''));
        }, $offers))));
    }

    /**
     * @return array<string, string>
     */
    private function extractTop20SocialLinks(string $html): array
    {
        if (! class_exists(\DOMDocument::class) || ! class_exists(\DOMXPath::class)) {
            return [];
        }

        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($dom);

        $networkMap = [
            'facebook' => [
                '//a[contains(@class, "ga-company-fb")]/@href',
            ],
            'instagram' => [
                '//a[contains(@class, "ga-company-instagram")]/@href',
                '//a[contains(@class, "gtm-company-chat-instagram")]/@href',
            ],
            'telegram' => [
                '//a[contains(@class, "ga-company-telegram")]/@href',
                '//a[contains(@class, "gtm-company-chat-telegram")]/@href',
            ],
            'tiktok' => [
                '//a[contains(@class, "ga-company-tiktok")]/@href',
            ],
            'youtube' => [
                '//a[contains(@class, "ga-company-youtube")]/@href',
            ],
            'x' => [
                '//a[contains(@class, "ga-company-tw")]/@href',
            ],
        ];

        $socialLinks = [];

        foreach ($networkMap as $network => $queries) {
            foreach ($queries as $query) {
                $nodes = $xpath->query($query);
                if (! $nodes) {
                    continue;
                }

                foreach ($nodes as $node) {
                    $url = $this->sanitizeSocialUrl(trim((string) $node->nodeValue));
                    if ($url === '') {
                        continue;
                    }

                    $socialLinks[$network] = $url;
                    continue 3;
                }
            }
        }

        return $socialLinks;
    }

    private function sanitizeSocialUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }

        if (! preg_match('#^https?://#i', $url) && str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }
}
