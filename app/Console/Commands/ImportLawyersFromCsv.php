<?php

namespace App\Console\Commands;

use App\Models\Lawyer;
use App\Models\Region;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportLawyersFromCsv extends Command
{
    protected $signature = 'dovira:import-lawyers
        {path : Path to CSV/TSV file or directory}
        {--delimiter= : CSV delimiter (default auto-detect: ; then , then \\t)}
        {--region= : Default region (name or slug) to assign when CSV has no region column}
        {--infer-region : Infer region from filename when CSV has no region column}
        {--dry-run : Parse and validate, but do not write to DB}
        {--truncate : Delete all lawyers before import}
        {--skip-invalid : Skip invalid rows instead of failing}';

    protected $description = 'Import lawyers into the database from CSV/TSV.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $delimiter = $this->option('delimiter');
        $defaultRegionOption = $this->option('region');
        $inferRegion = (bool) $this->option('infer-region');
        $dryRun = (bool) $this->option('dry-run');
        $truncate = (bool) $this->option('truncate');
        $skipInvalid = (bool) $this->option('skip-invalid');

        $realPath = realpath($path) ?: (realpath(base_path($path)) ?: null);
        if ($realPath === null || (! is_file($realPath) && ! is_dir($realPath))) {
            $this->error("CSV/TSV path not found: {$path}");
            return self::FAILURE;
        }

        $paths = [];
        if (is_dir($realPath)) {
            $paths = collect(File::files($realPath))
                ->filter(fn ($f) => in_array(mb_strtolower($f->getExtension()), ['csv', 'tsv'], true))
                ->map(fn ($f) => $f->getRealPath())
                ->filter(fn ($p) => is_string($p) && $p !== '')
                ->values()
                ->all();

            if ($paths === []) {
                $this->error("No .csv/.tsv files found in directory: {$realPath}");
                return self::FAILURE;
            }
        } else {
            $paths = [$realPath];
        }

        $defaultRegionSlug = null;
        $defaultRegionName = null;
        if (is_string($defaultRegionOption) && trim($defaultRegionOption) !== '') {
            $defaultRegionName = trim($defaultRegionOption);
            $defaultRegionSlug = Str::slug($defaultRegionName, '-');
        }

        $stats = [
            'files_processed' => 0,
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_skipped' => 0,
            'regions_created' => 0,
        ];

        $regionIdBySlug = Region::query()->pluck('id', 'slug')->all();

        $run = function () use (
            $paths,
            $delimiter,
            $defaultRegionSlug,
            $defaultRegionName,
            $inferRegion,
            $skipInvalid,
            $dryRun,
            &$stats,
            &$regionIdBySlug
        ): void {
            foreach ($paths as $p) {
                $this->importSingleFile(
                    $p,
                    is_string($delimiter) ? $delimiter : null,
                    $defaultRegionSlug,
                    $defaultRegionName,
                    $inferRegion,
                    $skipInvalid,
                    $dryRun,
                    $stats,
                    $regionIdBySlug
                );
            }
        };

        try {
            if ($dryRun) {
                $run();
            } else {
                DB::transaction(function () use ($truncate, $run): void {
                    if ($truncate) {
                        Lawyer::query()->delete();
                    }
                    $run();
                });
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Done.');
        $this->line("Files processed: {$stats['files_processed']}");
        $this->line("Rows processed: {$stats['rows_total']}");
        $this->line("Rows imported: {$stats['rows_imported']}");
        $this->line("Rows skipped:  {$stats['rows_skipped']}");
        if (! $dryRun) {
            $this->line("Regions created: {$stats['regions_created']}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $regionIdBySlug
     */
    private function importSingleFile(
        string $realPath,
        ?string $delimiterOption,
        ?string $defaultRegionSlug,
        ?string $defaultRegionName,
        bool $inferRegion,
        bool $skipInvalid,
        bool $dryRun,
        array &$stats,
        array &$regionIdBySlug
    ): void {
        $file = new \SplFileObject($realPath, 'r');
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);

        $delimiterToUse = $this->detectDelimiter($delimiterOption, $realPath);
        $file->setCsvControl($delimiterToUse);

        $file->rewind();
        $headerRow = $this->normalizeRow($file->fgetcsv());
        if ($headerRow === [] || count(array_filter($headerRow, fn ($v) => $v !== null && $v !== '')) === 0) {
            throw new \RuntimeException("CSV header row is empty: {$realPath}");
        }

        $headerMap = $this->buildHeaderMap($headerRow);
        if (! isset($headerMap['certificate_number']) || ! isset($headerMap['full_name'])) {
            throw new \RuntimeException("Missing required columns (ПІБ/Номер свідоцтва) in file: {$realPath}");
        }

        $fileDefaultRegionSlug = $defaultRegionSlug;
        $fileDefaultRegionName = $defaultRegionName;

        if ($fileDefaultRegionSlug === null && $inferRegion) {
            $guessed = $this->guessRegionNameFromFilename($realPath);
            if ($guessed !== null) {
                $fileDefaultRegionName = $guessed;
                $fileDefaultRegionSlug = Str::slug($guessed, '-');
            }
        }

        $stats['files_processed']++;
        $this->line(
            'File: '.basename($realPath)
            .' | delim: '.($delimiterToUse === "\t" ? '\\t' : $delimiterToUse)
            .' | region: '.($fileDefaultRegionSlug ? $fileDefaultRegionName : '—')
            .' | '.($dryRun ? 'dry-run' : 'import')
        );

        $batchByHash = [];
        $batchSize = 500;

        while (! $file->eof()) {
            $row = $this->normalizeRow($file->fgetcsv());
            if ($row === [] || (count($row) === 1 && $row[0] === null)) {
                continue;
            }

            $stats['rows_total']++;

            $data = $this->rowToLawyerData($row, $headerMap);
            $isValid = $data['certificate_number'] !== null && $data['full_name'] !== null;

            if (! $isValid) {
                $stats['rows_skipped']++;
                $message = "Row {$stats['rows_total']}: missing required fields (full_name/certificate_number).";
                if ($skipInvalid) {
                    $this->warn($message);
                    continue;
                }
                throw new \RuntimeException($message);
            }

            if ($data['region_slug'] === null && $fileDefaultRegionSlug !== null) {
                $data['region_slug'] = $fileDefaultRegionSlug;
                $data['region_name'] = $fileDefaultRegionName;
            }

            if ($data['region_slug'] !== null) {
                if (! isset($regionIdBySlug[$data['region_slug']])) {
                    if (! $dryRun) {
                        $region = Region::firstOrCreate(
                            ['slug' => $data['region_slug']],
                            ['name' => $data['region_name'] ?? $this->humanizeRegionSlug($data['region_slug'])]
                        );
                        $regionIdBySlug[$region->slug] = $region->id;
                        $stats['regions_created']++;
                    }
                }
                $data['region_id'] = $regionIdBySlug[$data['region_slug']] ?? null;
            }

            $data['source_hash'] = $this->computeSourceHash($data);

            unset($data['region_slug'], $data['region_name']);

            $batchByHash[(string) $data['source_hash']] = $data;
            if (count($batchByHash) >= $batchSize) {
                $this->flushBatch(array_values($batchByHash), $dryRun, $stats);
                $batchByHash = [];
            }
        }

        if ($batchByHash !== []) {
            $this->flushBatch(array_values($batchByHash), $dryRun, $stats);
        }
    }

    private function detectDelimiter(?string $delimiterOption, string $realPath): string
    {
        if (is_string($delimiterOption) && $delimiterOption !== '') {
            return $delimiterOption === '\\t' ? "\t" : $delimiterOption;
        }

        $firstLine = (string) @file_get_contents($realPath, false, null, 0, 4096);
        $counts = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $best = array_key_first($counts);
        if (is_string($best) && $counts[$best] > 0) {
            return $best;
        }

        return ';';
    }

    private function normalizeRow(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        return array_map(function ($value) {
            if ($value === null) {
                return null;
            }
            $value = trim((string) $value);
            if ($value === '') {
                return null;
            }

            return str_replace("\xEF\xBB\xBF", '', $value);
        }, $row);
    }

    private function buildHeaderMap(array $headers): array
    {
        $canon = [];
        foreach ($headers as $i => $raw) {
            if ($raw === null) {
                continue;
            }

            $h = $this->normalizeHeader((string) $raw);

            $key = match (true) {
                $this->matches($h, ['pib', 'fullname', 'full_name', 'name', 'прізвищеімяпобатькові', 'піб']) => 'full_name',
                $this->matches($h, ['certificate', 'certificatenumber', 'certificate_number', 'svydotstvo', 'номерсвідоцтва', 'свідоцтво', 'свідоцтвономер', 'свідоцтво№', 'номер']) => 'certificate_number',
                $this->matches($h, ['region', 'oblast', 'область', 'регіон', 'адміністративнаодиниця']) => 'region_name',
                $this->matches($h, ['certificateissuedat', 'certificate_issued_at', 'issuedat', 'datavyдачi', 'датавидачі', 'датавидачи', 'дата']) => 'certificate_issued_at',
                $this->matches($h, ['certificateissuer', 'certificate_issuer', 'issuer', 'organ', 'орган', 'органвидачі', 'органвидачи', 'кдка']) => 'certificate_issuer',
                $this->matches($h, ['decisionnumber', 'decision_number', 'номеррішення', 'номеррешения', 'рішенняномер']) => 'decision_number',
                $this->matches($h, ['decisionat', 'decision_at', 'датарішення', 'датарешения']) => 'decision_at',
                $this->matches($h, ['email', 'e-mail', 'електроннапошта', 'пошта']) => 'email',
                $this->matches($h, ['photo', 'photourl', 'photo_url', 'avatar', 'фото', 'посиланнянафото', 'urlфото']) => 'photo_url',
                $this->matches($h, ['issuspended', 'is_suspended', 'suspended', 'зупинено', 'статус']) => 'is_suspended',
                $this->matches($h, ['notes', 'note', 'примітка', 'інші', 'іншівідомості', 'коментар']) => 'notes',
                default => null,
            };

            if ($key !== null && ! isset($canon[$key])) {
                $canon[$key] = $i;
            }
        }

        return $canon;
    }

    private function normalizeHeader(string $raw): string
    {
        $raw = trim(str_replace("\xEF\xBB\xBF", '', $raw));
        $raw = mb_strtolower($raw);
        $raw = preg_replace('/[^a-z0-9а-яіїєґ]+/u', '', $raw) ?? $raw;

        return $raw;
    }

    private function matches(string $normalizedHeader, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($normalizedHeader === $this->normalizeHeader((string) $needle)) {
                return true;
            }
        }
        return false;
    }

    private function rowToLawyerData(array $row, array $headerMap): array
    {
        $get = function (string $key) use ($row, $headerMap): ?string {
            if (! isset($headerMap[$key])) {
                return null;
            }
            $value = $row[$headerMap[$key]] ?? null;
            if ($value === null) {
                return null;
            }
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        };

        $fullName = $get('full_name');
        $certificateNumber = $get('certificate_number');
        if ($certificateNumber !== null) {
            $certificateNumber = preg_replace('/\s+/', '', $certificateNumber) ?? $certificateNumber;
        }

        $regionName = $get('region_name');
        $regionSlug = $regionName ? Str::slug($regionName, '-') : null;

        return [
            'full_name' => $fullName,
            'certificate_number' => $certificateNumber,
            'certificate_issued_at' => $this->parseDate($get('certificate_issued_at')),
            'certificate_issuer' => $get('certificate_issuer'),
            'decision_number' => $get('decision_number'),
            'decision_at' => $this->parseDate($get('decision_at')),
            'email' => $get('email'),
            'photo_url' => $get('photo_url'),
            'is_suspended' => $this->parseBool($get('is_suspended')),
            'notes' => $get('notes'),
            'region_slug' => $regionSlug,
            'region_name' => $regionName,
        ];
    }

    private function parseBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $v = mb_strtolower(trim($value));
        return in_array($v, ['1', 'true', 'yes', 'y', 'так', 'да', 'зупинено', 'suspended'], true);
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $value = str_replace(['/', '.'], '-', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function humanizeRegionSlug(string $slug): string
    {
        return Str::of($slug)->replace('-', ' ')->title()->toString();
    }

    private function guessRegionNameFromFilename(string $path): ?string
    {
        $name = (string) pathinfo($path, PATHINFO_FILENAME);
        $name = str_replace('_', ' ', $name);
        $name = mb_strtolower($this->stripCombiningMarks($name));

        if (str_contains($name, 'міста') && (str_contains($name, 'києва') || str_contains($name, 'київ'))) {
            return 'м. Київ';
        }

        if (str_contains($name, 'севастопол')) {
            return 'м. Севастополь';
        }

        $patterns = [
            '/вінниц/' => 'Вінницька область',
            '/волин/' => 'Волинська область',
            '/дніпропетр/' => 'Дніпропетровська область',
            '/донецьк/' => 'Донецька область',
            '/житомир/' => 'Житомирська область',
            '/закарпат/' => 'Закарпатська область',
            '/запоріз/' => 'Запорізька область',
            '/івано\\s*франк|ивано\\s*франк|франків/' => 'Івано-Франківська область',
            '/київськ/' => 'Київська область',
            '/кіровоград|кировоград/' => 'Кіровоградська область',
            '/луганськ/' => 'Луганська область',
            '/львів/' => 'Львівська область',
            '/микола/' => 'Миколаївська область',
            '/одес/' => 'Одеська область',
            '/полтав/' => 'Полтавська область',
            '/рівнен/' => 'Рівненська область',
            '/сумськ/' => 'Сумська область',
            '/терноп/' => 'Тернопільська область',
            '/харків/' => 'Харківська область',
            '/херсон/' => 'Херсонська область',
            '/хмельниц/' => 'Хмельницька область',
            '/черкас/' => 'Черкаська область',
            '/чернівц|чернівець|черновц|черновец/' => 'Чернівецька область',
            '/чернігів/' => 'Чернігівська область',
        ];

        foreach ($patterns as $pattern => $regionName) {
            if (preg_match($pattern, $name) === 1) {
                return $regionName;
            }
        }

        return null;
    }

    private function stripCombiningMarks(string $value): string
    {
        return preg_replace('/\\p{Mn}+/u', '', $value) ?? $value;
    }

    private function flushBatch(array $batch, bool $dryRun, array &$stats): void
    {
        if ($batch === []) {
            return;
        }

        $batchByHash = [];
        foreach ($batch as $row) {
            $batchByHash[(string) ($row['source_hash'] ?? '')] = $row;
        }
        $batch = array_values($batchByHash);

        if ($dryRun) {
            $stats['rows_imported'] += count($batch);
            return;
        }

        Lawyer::query()->upsert(
            $batch,
            ['source_hash'],
            [
                'source_hash',
                'full_name',
                'certificate_issued_at',
                'certificate_issuer',
                'decision_number',
                'decision_at',
                'email',
                'photo_url',
                'is_suspended',
                'notes',
                'certificate_number',
                'region_id',
                'updated_at',
            ],
        );

        $stats['rows_imported'] += count($batch);
    }

    private function computeSourceHash(array $data): string
    {
        $basis = [
            'region_id' => $data['region_id'] ?? null,
            'full_name' => $data['full_name'] ?? null,
            'certificate_number' => $data['certificate_number'] ?? null,
            'certificate_issuer' => $data['certificate_issuer'] ?? null,
            'certificate_issued_at' => $data['certificate_issued_at'] ?? null,
            'decision_number' => $data['decision_number'] ?? null,
            'decision_at' => $data['decision_at'] ?? null,
            'email' => $data['email'] ?? null,
        ];

        return sha1(json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
