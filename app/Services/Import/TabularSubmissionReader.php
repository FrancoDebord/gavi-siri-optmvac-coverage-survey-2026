<?php

namespace App\Services\Import;

use App\Services\Dictionary\CsvReader;
use App\Services\Dictionary\Dictionary;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reconstitue des soumissions à partir d'exports tabulaires :
 * - ODK Central : fichier principal + un CSV par repeat, liés par KEY / PARENT_KEY ;
 * - KoboToolbox : classeur XLSX (une feuille par repeat), liés par _index / _parent_index.
 * Les fichiers peuvent aussi être fournis séparément (CSV ou XLSX).
 */
class TabularSubmissionReader
{
    private const ODK_META = [
        'SubmissionDate', 'KEY', 'PARENT_KEY', 'SubmitterID', 'SubmitterName', 'AttachmentsPresent',
        'AttachmentsExpected', 'Status', 'ReviewState', 'DeviceID', 'Edits', 'FormVersion',
        'meta-instanceID', 'meta-instanceName', 'meta-audit', 'instanceID', 'instanceName',
    ];

    private const GEO_PARTS = ['Latitude' => 0, 'Longitude' => 1, 'Altitude' => 2, 'Accuracy' => 3];

    /** @var list<array{name: string, rows: list<array<string, mixed>>}> */
    private array $tables = [];

    public function __construct(private readonly Dictionary $dictionary) {}

    /**
     * @param  list<array{path: string, name: string}>  $files  le premier fichier est le fichier principal
     */
    public function load(array $files): self
    {
        $this->tables = [];
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($extension === 'csv' || $extension === 'txt') {
                $this->tables[] = ['name' => pathinfo($file['name'], PATHINFO_FILENAME), 'rows' => CsvReader::read($file['path'])];

                continue;
            }
            if (! in_array($extension, ['xlsx', 'xls'], true)) {
                throw new InvalidArgumentException("Format non pris en charge : {$file['name']}");
            }
            $reader = IOFactory::createReaderForFile($file['path']);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file['path']);
            foreach ($spreadsheet->getSheetNames() as $sheetName) {
                $rows = $spreadsheet->getSheetByName($sheetName)->toArray(null, false, false, false);
                $header = array_map(fn ($h) => trim((string) $h), array_shift($rows) ?? []);
                $assoc = [];
                foreach ($rows as $row) {
                    if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                        continue;
                    }
                    $assoc[] = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
                }
                $this->tables[] = ['name' => $sheetName, 'rows' => $assoc];
            }
            $spreadsheet->disconnectWorksheets();
        }

        if ($this->tables === []) {
            throw new InvalidArgumentException('Aucun fichier de données fourni.');
        }

        return $this;
    }

    public function format(): string
    {
        $header = array_keys($this->tables[0]['rows'][0] ?? []);

        return in_array('_index', $header, true) || in_array('_uuid', $header, true) ? 'kobo' : 'odk';
    }

    public function count(): int
    {
        return count($this->tables[0]['rows']);
    }

    /**
     * @return iterable<NormalizedSubmission>
     */
    public function submissions(): iterable
    {
        $kobo = $this->format() === 'kobo';
        $mainTable = $this->tables[0]['name'];

        // 1. Index de toutes les lignes de repeat par identifiant de parent.
        $children = [];
        foreach (array_slice($this->tables, 1) as $table) {
            $repeat = $this->repeatName($table['name']);
            foreach ($table['rows'] as $row) {
                if ($kobo) {
                    $parentTable = $row['_parent_table_name'] ?? $mainTable;
                    $parentId = $this->koboTableKey($parentTable, $mainTable).'#'.($row['_parent_index'] ?? '');
                    $id = $this->koboTableKey($table['name'], $mainTable).'#'.($row['_index'] ?? '');
                } else {
                    $parentId = (string) ($row['PARENT_KEY'] ?? '');
                    $id = (string) ($row['KEY'] ?? '');
                }
                $children[$parentId][$repeat][] = ['id' => $id, 'row' => $row];
            }
        }

        // 2. Assemblage récursif de chaque soumission.
        foreach ($this->tables[0]['rows'] as $row) {
            $id = $kobo
                ? $this->koboTableKey($mainTable, $mainTable).'#'.($row['_index'] ?? '')
                : (string) ($row['KEY'] ?? $row['meta-instanceID'] ?? '');
            $node = $this->node($id, $row, $children);

            $uuid = $kobo
                ? ($row['meta/instanceID'] ?? (isset($row['_uuid']) ? 'uuid:'.$row['_uuid'] : ''))
                : ($row['meta-instanceID'] ?? $row['KEY'] ?? '');

            if ($uuid === '' || $uuid === null) {
                continue;
            }

            yield new NormalizedSubmission(
                uuid: (string) $uuid,
                data: $node['data'],
                repeats: $node['repeats'],
                raw: ['row' => $row, 'repeats' => $node['repeats']],
                submittedAt: $this->stringValue($kobo ? ($row['_submission_time'] ?? null) : ($row['SubmissionDate'] ?? null)),
                formVersion: $this->stringValue($kobo ? ($row['__version__'] ?? null) : ($row['FormVersion'] ?? null)),
                reviewState: $this->stringValue($row['ReviewState'] ?? $row['_validation_status'] ?? null),
                submitter: $this->stringValue($row['SubmitterName'] ?? $row['_submitted_by'] ?? null),
            );
        }
    }

    private function node(string $id, array $row, array $children): array
    {
        $repeats = [];
        foreach ($children[$id] ?? [] as $repeat => $items) {
            foreach ($items as $item) {
                $child = $this->node($item['id'], $item['row'], $children);
                $child['key'] = $item['id'];
                $repeats[$repeat][] = $child;
            }
        }

        return ['key' => $id, 'data' => $this->flattenRow($row), 'repeats' => $repeats];
    }

    /** Colonnes d'export → variables XLSForm. */
    public function flattenRow(array $row): array
    {
        $data = [];
        $geo = [];

        foreach ($row as $column => $value) {
            $column = trim((string) $column);
            if ($column === '' || in_array($column, self::ODK_META, true) || str_starts_with($column, '_') || str_starts_with($column, 'meta/') || str_starts_with($column, 'formhub/')) {
                continue;
            }

            // Géopoint éclaté par ODK : « current_location-Latitude ».
            if (preg_match('/^(.*)-(Latitude|Longitude|Altitude|Accuracy)$/', $column, $m)) {
                $geo[$this->columnName($m[1])][self::GEO_PARTS[$m[2]]] = $value;

                continue;
            }

            $segments = preg_split('#[/]#', $column);
            $name = $this->columnName($column);
            // Colonnes binaires des select_multiple (Kobo « var/option ») : ignorées.
            if (count($segments) > 1 && ! $this->dictionary->has($name)) {
                $parent = $this->columnName(implode('/', array_slice($segments, 0, -1)));
                if (($this->dictionary->variable($parent)['base_type'] ?? null) === 'select_multiple') {
                    continue;
                }
            }

            $data[$name] = $this->cellValue($name, $value);
        }

        foreach ($geo as $name => $parts) {
            ksort($parts);
            if (($parts[0] ?? '') !== '' && $parts[0] !== null) {
                $data[$name] = trim(implode(' ', array_map(fn ($p) => (string) $p, $parts)));
            }
        }

        return $data;
    }

    private function columnName(string $column): string
    {
        $name = SubmissionNormalizer::leaf($column);
        if ($this->dictionary->has($name) || ! str_contains($name, '-')) {
            return $name;
        }
        // ODK sépare les groupes par « - » : section2-num_slept_last_night.
        $parts = explode('-', $name);

        return trim(end($parts));
    }

    private function cellValue(string $name, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $type = $this->dictionary->variable($name)['base_type'] ?? null;
        if (is_numeric($value) && in_array($type, ['date', 'datetime', 'start', 'end', 'today'], true) && (float) $value > 20000) {
            $date = ExcelDate::excelToDateTimeObject((float) $value);

            return $type === 'date' || $type === 'today' ? $date->format('Y-m-d') : $date->format('Y-m-d\TH:i:s');
        }

        return SubmissionNormalizer::scalar(is_string($value) ? trim($value) : $value);
    }

    /** Nom du repeat d'après le nom du fichier ou de la feuille (« formulaire-cg » → « cg »). */
    private function repeatName(string $tableName): string
    {
        $known = $this->dictionary->repeatNames();
        usort($known, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($known as $repeat) {
            if ($tableName === $repeat || str_ends_with($tableName, '-'.$repeat) || str_ends_with($tableName, '_'.$repeat)
                || (strlen($tableName) >= 31 && str_starts_with($repeat, $tableName))) {
                return $repeat;
            }
        }
        $parts = explode('-', $tableName);

        return end($parts);
    }

    private function koboTableKey(string $tableName, string $mainTable): string
    {
        if ($tableName === $mainTable) {
            return '__main';
        }
        $repeat = $this->repeatName($tableName);

        // Kobo nomme la table principale d'après le titre du formulaire : tout ce qui n'est pas un repeat connu est la racine.
        return $this->dictionary->repeatNames() === [] || in_array($repeat, $this->dictionary->repeatNames(), true) ? $repeat : '__main';
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value['uid'] ?? json_encode($value);
        }

        return (string) $value;
    }
}
