<?php

namespace App\Services\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Illuminate\Support\Str;

class SqliteToMysqlMigrator
{
    /**
     * @return array<string, mixed>
     */
    public function migrate(string $sourcePath, ?string $destinationConnection = null, bool $truncate = false): array
    {
        $destinationConnection ??= config('database.default');

        if ($destinationConnection === 'sqlite') {
            throw new RuntimeException('Destination connection must not be sqlite.');
        }

        if (! is_file($sourcePath)) {
            throw new RuntimeException("Source SQLite file not found: {$sourcePath}");
        }

        $sqlite = new PDO('sqlite:' . $sourcePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $destination = DB::connection($destinationConnection);
        $tables = $this->sourceTables($sqlite);

        if ($tables === []) {
            throw new RuntimeException('No source tables found in SQLite database.');
        }

        $missingTables = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::connection($destinationConnection)->hasTable($table)
        ));

        if ($missingTables !== []) {
            throw new RuntimeException('Missing destination tables: ' . implode(', ', $missingTables));
        }

        $summary = [
            'source_path' => $sourcePath,
            'destination_connection' => $destinationConnection,
            'tables' => [],
        ];

        $destination->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $destinationColumns = array_flip(Schema::connection($destinationConnection)->getColumnListing($table));
                $destinationColumnMeta = $this->destinationColumnMeta($destinationConnection, $table);

                if ($truncate) {
                    $destination->table($table)->truncate();
                }

                $statement = $sqlite->query(sprintf('SELECT * FROM "%s"', str_replace('"', '""', $table)));
                $batch = [];
                $inserted = 0;

                while (($row = $statement->fetch()) !== false) {
                    $filtered = [];

                    foreach ($row as $column => $value) {
                        if (isset($destinationColumns[$column])) {
                            $filtered[$column] = $this->normalizeValueForDestination(
                                $value,
                                $destinationColumnMeta[$column] ?? null
                            );
                        }
                    }

                    if ($filtered === []) {
                        continue;
                    }

                    $batch[] = $filtered;

                    if (count($batch) >= 500) {
                        $destination->table($table)->insert($batch);
                        $inserted += count($batch);
                        $batch = [];
                    }
                }

                if ($batch !== []) {
                    $destination->table($table)->insert($batch);
                    $inserted += count($batch);
                }

                $summary['tables'][$table] = $inserted;
            }
        } finally {
            $destination->statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $summary;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function destinationColumnMeta(string $connection, string $table): array
    {
        $rows = DB::connection($connection)->select("SHOW COLUMNS FROM `{$table}`");
        $meta = [];

        foreach ($rows as $row) {
            $field = (string) ($row->Field ?? '');
            $type = Str::lower((string) ($row->Type ?? ''));
            $length = null;

            if (preg_match('/^(?:varchar|char)\((\d+)\)/', $type, $matches) === 1) {
                $length = (int) $matches[1];
            }

            $meta[$field] = [
                'type' => $type,
                'length' => $length,
            ];
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function normalizeValueForDestination(mixed $value, ?array $meta): mixed
    {
        if (! is_string($value) || $meta === null) {
            return $value;
        }

        $length = $meta['length'] ?? null;

        if (is_int($length) && $length > 0 && mb_strlen($value) > $length) {
            return mb_substr($value, 0, $length);
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function sourceTables(PDO $sqlite): array
    {
        $statement = $sqlite->query('SELECT name FROM sqlite_master WHERE type = "table" AND name NOT LIKE "sqlite_%" ORDER BY name');

        return array_map(
            fn (array $row) => (string) $row['name'],
            $statement->fetchAll()
        );
    }
}
