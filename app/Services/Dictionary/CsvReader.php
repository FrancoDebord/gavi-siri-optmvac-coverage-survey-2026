<?php

namespace App\Services\Dictionary;

/** Lecture simple d'un CSV avec en-tête (gère le BOM UTF-8 et le séparateur ; ou ,). */
class CsvReader
{
    /**
     * @return list<array<string, string>>
     */
    public static function read(string $path): array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            return [];
        }

        $first = fgets($handle);
        if ($first === false) {
            fclose($handle);

            return [];
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => trim($h), str_getcsv($first, $delimiter, '"', '\\'));

        $rows = [];
        while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
