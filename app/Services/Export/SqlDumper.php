<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copie complète de la base de données en SQL (structure + données), sans dépendre de mysqldump
 * (souvent indisponible sur un hébergement mutualisé). Les tables techniques volatiles (cache, sessions,
 * file d'attente) sont omises. Restauration : importer le fichier dans une base vide (mysql ou phpMyAdmin).
 */
class SqlDumper
{
    /** Tables techniques non sauvegardées (reconstruites à l'usage). */
    public const SKIPPED = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches'];

    /** @return array<string, int> table => nombre de lignes */
    public function dump(string $path): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $handle = fopen($path, 'w');
        $counts = [];

        fwrite($handle, '-- Copie complète de la base « '.$connection->getDatabaseName().' » ('.config('app.name').")\n");
        fwrite($handle, '-- Générée le '.now()->toIso8601String()." ; pilote {$driver}\n\n");
        if ($driver === 'mysql') {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        } else {
            fwrite($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
        }

        // MySQL : seulement les tables de la base de l'application (un hébergement peut en avoir plusieurs).
        $schema = DB::connection()->getDriverName() === 'sqlite' ? null : DB::connection()->getDatabaseName();
        $tables = collect(Schema::getTableListing(schema: $schema, schemaQualified: false))->reject(fn ($t) => in_array($t, self::SKIPPED, true) || str_starts_with($t, 'sqlite_'))->sort()->values();
        foreach ($tables as $table) {
            $quoted = $driver === 'mysql' ? "`{$table}`" : "\"{$table}\"";
            fwrite($handle, "-- Table {$table}\nDROP TABLE IF EXISTS {$quoted};\n");
            $create = $driver === 'mysql'
                ? (array) DB::selectOne("SHOW CREATE TABLE {$quoted}")
                : ['sql' => DB::selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table])?->sql];
            fwrite($handle, rtrim((string) ($create['Create Table'] ?? $create['sql'] ?? ''), ";\n").";\n");

            $columns = Schema::getColumnListing($table);
            $columnList = implode(', ', array_map(fn ($c) => $driver === 'mysql' ? "`{$c}`" : "\"{$c}\"", $columns));
            $batch = [];
            $counts[$table] = 0;
            // Lecture par pages (le curseur MySQL mettrait toute la table en mémoire), dans un ordre stable :
            // par identifiant, sinon par toutes les colonnes (tables pivots).
            $rows = DB::table($table);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $rows->orderBy($column);
            }
            foreach ($rows->lazy(500) as $row) {
                $batch[] = '('.implode(', ', array_map(fn ($value) => match (true) {
                    $value === null => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_int($value), is_float($value) => (string) $value,
                    default => $pdo->quote((string) $value),
                }, array_values((array) $row))).')';
                $counts[$table]++;
                if (count($batch) === 200) {
                    fwrite($handle, "INSERT INTO {$quoted} ({$columnList}) VALUES\n".implode(",\n", $batch).";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                fwrite($handle, "INSERT INTO {$quoted} ({$columnList}) VALUES\n".implode(",\n", $batch).";\n");
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, $driver === 'mysql' ? "SET FOREIGN_KEY_CHECKS=1;\n" : "COMMIT;\n");
        fclose($handle);

        return $counts;
    }
}
