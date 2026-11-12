<?php

namespace App\Services\Dictionary;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lit un XLSForm (.xls/.xlsx) ou le contenu JSON d'un asset KoboToolbox
 * et le ramène à une structure commune :
 *   ['settings' => [...], 'variables' => [...], 'choices' => [...]]
 */
class XlsFormParser
{
    private const STRUCTURAL = ['begin group', 'end group', 'begin repeat', 'end repeat'];

    /**
     * @return array{settings: array<string, mixed>, variables: list<array<string, mixed>>, choices: list<array<string, mixed>>}
     */
    public function parseFile(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Fichier XLSForm introuvable : {$path}");
        }

        $spreadsheet = IOFactory::load($path);
        $sheets = [];
        foreach ($spreadsheet->getSheetNames() as $name) {
            $sheets[strtolower(trim($name))] = $this->sheetRows($spreadsheet->getSheetByName($name)->toArray(null, true, false, false));
        }

        if (! isset($sheets['survey'])) {
            throw new InvalidArgumentException('La feuille « survey » est absente du XLSForm.');
        }

        $settings = $sheets['settings'][0] ?? [];
        $language = $settings['default_language'] ?? null;

        return [
            'settings' => $settings,
            'variables' => $this->buildVariables($sheets['survey'], $language),
            'choices' => $this->buildChoices($sheets['choices'] ?? [], $language),
        ];
    }

    /**
     * Contenu d'un asset Kobo (GET /api/v2/assets/{uid}/?format=json → content).
     *
     * @param  array<string, mixed>  $content
     */
    public function parseKoboContent(array $content): array
    {
        $translations = $content['translations'] ?? [null];
        $settings = $content['settings'] ?? [];
        $languageIndex = 0;
        if (! empty($settings['default_language'])) {
            $found = array_search($settings['default_language'], $translations, true);
            $languageIndex = $found === false ? 0 : $found;
        }

        $pick = fn ($value) => is_array($value) ? ($value[$languageIndex] ?? reset($value) ?: null) : $value;

        $survey = array_map(function (array $row) use ($pick) {
            $type = (string) ($row['type'] ?? '');
            if (! empty($row['select_from_list_name'])) {
                $type .= ' '.$row['select_from_list_name'];
            }

            return [
                'type' => $type,
                'name' => $row['name'] ?? $row['$autoname'] ?? '',
                'label' => $pick($row['label'] ?? null),
                'hint' => $pick($row['hint'] ?? null),
                'required' => ($row['required'] ?? false) === true ? 'yes' : (string) ($row['required'] ?? ''),
                'relevant' => $row['relevant'] ?? null,
                'constraint' => $row['constraint'] ?? null,
                'constraint_message' => $pick($row['constraint_message'] ?? null),
                'calculation' => $row['calculation'] ?? null,
                'appearance' => $row['appearance'] ?? null,
                'choice_filter' => $row['choice_filter'] ?? null,
            ];
        }, $content['survey'] ?? []);

        $choices = array_map(fn (array $row) => [
            'list_name' => $row['list_name'] ?? '',
            'name' => $row['name'] ?? $row['$autovalue'] ?? '',
            'label' => $pick($row['label'] ?? null),
        ], $content['choices'] ?? []);

        return [
            'settings' => $settings,
            'variables' => $this->buildVariables($survey, null),
            'choices' => $this->buildChoices($choices, null),
        ];
    }

    /**
     * Transforme une feuille en lignes associatives (en-têtes normalisés).
     *
     * @param  list<list<mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sheetRows(array $rows): array
    {
        $header = array_shift($rows) ?? [];
        $keys = array_map(fn ($h) => $this->normalizeHeader((string) $h), $header);

        $result = [];
        foreach ($rows as $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $assoc = [];
            foreach ($keys as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $value = $row[$i] ?? null;
                $assoc[$key] = is_string($value) ? trim($value) : $value;
            }
            $result[] = $assoc;
        }

        return $result;
    }

    private function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        [$column, $language] = array_pad(preg_split('/\s*::\s*/', $header, 2), 2, null);

        // « constraint message » / « constraint_message », « read only » … ; la langue reste intacte.
        $column = str_replace(' ', '_', $column);

        return $language === null ? $column : $column.'::'.$language;
    }

    /** Colonne libellée dans la langue par défaut, sinon la première disponible. */
    private function localized(array $row, string $column, ?string $language): ?string
    {
        if ($language && ! empty($row[$column.'::'.strtolower($language)])) {
            return (string) $row[$column.'::'.strtolower($language)];
        }
        if (! empty($row[$column])) {
            return (string) $row[$column];
        }
        foreach ($row as $key => $value) {
            if (str_starts_with($key, $column.'::') && $value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildVariables(array $rows, ?string $language): array
    {
        $stack = [];
        $variables = [];
        $position = 0;

        foreach ($rows as $row) {
            $rawType = preg_replace('/\s+/', ' ', trim((string) ($row['type'] ?? '')));
            if ($rawType === '') {
                continue;
            }
            [$baseType, $listName] = $this->splitType($rawType);
            $name = trim((string) ($row['name'] ?? ''));

            if ($baseType === 'end group' || $baseType === 'end repeat') {
                array_pop($stack);
                $variables[] = $this->variable(++$position, $name, $rawType, $baseType, null, $row, $language, $stack);

                continue;
            }

            $variables[] = $this->variable(++$position, $name, $rawType, $baseType, $listName, $row, $language, $stack);

            if ($baseType === 'begin group' || $baseType === 'begin repeat') {
                $stack[] = ['name' => $name, 'repeat' => $baseType === 'begin repeat'];
            }
        }

        return $variables;
    }

    private function variable(int $position, string $name, string $rawType, string $baseType, ?string $listName, array $row, ?string $language, array $stack): array
    {
        $repeat = null;
        foreach ($stack as $frame) {
            if ($frame['repeat']) {
                $repeat = $frame['name'];
            }
        }

        $required = strtolower(trim((string) ($row['required'] ?? '')));

        return [
            'position' => $position,
            'name' => $name,
            'type' => $rawType,
            'base_type' => $baseType,
            'list_name' => $listName,
            'label' => $this->localized($row, 'label', $language),
            'hint' => $this->localized($row, 'hint', $language),
            'required' => in_array($required, ['yes', 'true', 'true()', '1'], true),
            'constraint' => $this->clean($row['constraint'] ?? null),
            'constraint_message' => $this->localized($row, 'constraint_message', $language),
            'relevant' => $this->clean($row['relevant'] ?? null),
            'calculation' => $this->clean($row['calculation'] ?? null),
            'appearance' => $this->clean($row['appearance'] ?? null),
            'choice_filter' => $this->clean($row['choice_filter'] ?? null),
            'group_path' => implode('/', array_column($stack, 'name')) ?: null,
            'repeat_name' => $repeat,
        ];
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * « select_one yn » → ['select_one', 'yn'] ; « begin_group » → ['begin group', null].
     *
     * @return array{0: string, 1: string|null}
     */
    public function splitType(string $type): array
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/^(begin|end)[_ ](group|repeat)$/', '$1 $2', $type);
        if (in_array($type, self::STRUCTURAL, true)) {
            return [$type, null];
        }

        if (preg_match('/^(select_one_from_file|select_multiple_from_file|select_one|select_multiple|rank)\s+(.+)$/', $type, $m)) {
            return [$m[1], trim($m[2])];
        }
        if (preg_match('/^(select_one|select_multiple)_external\s+(.+)$/', $type, $m)) {
            return [$m[1], trim($m[2])];
        }

        return [explode(' ', $type)[0], null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function buildChoices(array $rows, ?string $language): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $list = trim((string) ($row['list_name'] ?? $row['list name'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($list === '' || $name === '') {
                continue;
            }
            $extra = array_filter(
                array_diff_key($row, array_flip(['list_name', 'name', 'label'])),
                fn ($v, $k) => $v !== null && $v !== '' && ! str_starts_with($k, 'label::') && ! str_starts_with($k, 'media::'),
                ARRAY_FILTER_USE_BOTH,
            );
            $choices[] = [
                'list_name' => $list,
                'name' => $name,
                'label' => $this->localized($row, 'label', $language),
                'extra' => $extra ?: null,
            ];
        }

        return $choices;
    }
}
