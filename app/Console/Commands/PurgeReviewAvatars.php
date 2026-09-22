<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class PurgeReviewAvatars extends Command
{
    protected $signature = 'dovira:purge-review-avatars
        {--write : Actually update database and delete files. Without this option the command is dry-run}
        {--keep-database : Do not null profile_reviews.external_review_author_avatar_url}
        {--keep-files : Do not delete stored avatar files and generated thumbnails}';

    protected $description = 'Free inodes by removing localized external review avatars and their thumbnail cache.';

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        $keepDatabase = (bool) $this->option('keep-database');
        $keepFiles = (bool) $this->option('keep-files');

        $dbRows = $this->localizedReviewAvatarRowsCount();
        $storedFiles = $this->countStorageFiles('catalog-media/review-avatars');
        $thumbFiles = $this->countPublicThumbFiles();

        $this->table(['Target', 'Files / rows'], [
            ['DB rows with localized review avatar path', (string) $dbRows],
            ['storage/app/public/catalog-media/review-avatars files', (string) $storedFiles],
            ['public/media/thumb files for review avatars', (string) $thumbFiles],
        ]);

        if (! $write) {
            $this->warn('Dry-run only. Re-run with --write to purge.');

            return self::SUCCESS;
        }

        $databaseUpdated = 0;
        if (! $keepDatabase && $dbRows > 0) {
            $databaseUpdated = $this->localizedReviewAvatarRowsQuery()
                ->update(['external_review_author_avatar_url' => null]);
        }

        $storageDeleted = 0;
        $thumbsDeleted = 0;
        if (! $keepFiles) {
            $storageDeleted = $this->deleteStorageDirectoryFiles('catalog-media/review-avatars');
            $thumbsDeleted = $this->deletePublicThumbFiles();
        }

        $this->newLine();
        $this->info('Review avatar purge completed.');
        $this->table(['Metric', 'Value'], [
            ['DB rows nulled', (string) $databaseUpdated],
            ['Stored avatar files deleted', (string) $storageDeleted],
            ['Thumb files deleted', (string) $thumbsDeleted],
        ]);

        return self::SUCCESS;
    }

    private function localizedReviewAvatarRowsCount(): int
    {
        return (int) $this->localizedReviewAvatarRowsQuery()->count();
    }

    private function localizedReviewAvatarRowsQuery()
    {
        return DB::table('profile_reviews')
            ->where(function ($query): void {
                $query
                    ->where('external_review_author_avatar_url', 'like', 'catalog-media/review-avatars/%')
                    ->orWhere('external_review_author_avatar_url', 'like', '/media/catalog-media/review-avatars/%')
                    ->orWhere('external_review_author_avatar_url', 'like', 'media/catalog-media/review-avatars/%')
                    ->orWhere('external_review_author_avatar_url', 'like', '/storage/catalog-media/review-avatars/%')
                    ->orWhere('external_review_author_avatar_url', 'like', 'storage/catalog-media/review-avatars/%');
            });
    }

    private function countStorageFiles(string $directory): int
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($directory)) {
            return 0;
        }

        return count($disk->allFiles($directory));
    }

    private function deleteStorageDirectoryFiles(string $directory): int
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($directory)) {
            return 0;
        }

        $files = $disk->allFiles($directory);
        foreach (array_chunk($files, 1000) as $chunk) {
            $disk->delete($chunk);
        }

        $disk->deleteDirectory($directory);

        return count($files);
    }

    private function countPublicThumbFiles(): int
    {
        return count($this->publicThumbFiles());
    }

    private function deletePublicThumbFiles(): int
    {
        $files = $this->publicThumbFiles();
        foreach ($files as $file) {
            File::delete($file);
        }

        $this->removeEmptyDirectories(public_path('media/thumb'));

        return count($files);
    }

    /**
     * @return array<int, string>
     */
    private function publicThumbFiles(): array
    {
        $root = public_path('media/thumb');
        if (! is_dir($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->map(fn ($file): string => $file->getPathname())
            ->filter(fn (string $path): bool => str_contains($path, 'catalog-media/review-avatars'))
            ->values()
            ->all();
    }

    private function removeEmptyDirectories(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }

        $directories = collect(File::directories($root))
            ->flatMap(function (string $directory): array {
                return array_merge([$directory], File::allDirectories($directory));
            })
            ->sortByDesc(fn (string $directory): int => substr_count($directory, DIRECTORY_SEPARATOR));

        foreach ($directories as $directory) {
            if (@count(scandir($directory) ?: []) <= 2) {
                @rmdir($directory);
            }
        }
    }
}
