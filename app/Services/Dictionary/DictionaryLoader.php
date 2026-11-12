<?php

namespace App\Services\Dictionary;

use App\Models\FormChoice;
use App\Models\FormVariable;
use App\Models\FormVersion;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre un dictionnaire parsé en base (versionné par form_id + version)
 * et l'active. Les listes « select_one_from_file » sont complétées à partir
 * des CSV présents dans le dossier de paramètres (l1.csv, village.csv…).
 */
class DictionaryLoader
{
    public function __construct(private readonly XlsFormParser $parser) {}

    public function loadFile(string $path, string $source = 'file', ?int $dataSourceId = null): FormVersion
    {
        return $this->store($this->parser->parseFile($path), $source, $path, $dataSourceId);
    }

    public function loadKoboContent(array $content, ?int $dataSourceId = null): FormVersion
    {
        return $this->store($this->parser->parseKoboContent($content), 'api', null, $dataSourceId);
    }

    /**
     * @param  array{settings: array, variables: list<array>, choices: list<array>}  $parsed
     */
    public function store(array $parsed, string $source, ?string $filePath = null, ?int $dataSourceId = null): FormVersion
    {
        $settings = $parsed['settings'];
        $formId = (string) ($settings['form_id'] ?? $settings['id_string'] ?? 'formulaire');
        $version = (string) ($settings['version'] ?? now()->format('YmdHis'));

        $formVersion = DB::transaction(function () use ($parsed, $settings, $formId, $version, $source, $filePath, $dataSourceId) {
            $formVersion = FormVersion::updateOrCreate(
                ['form_id' => $formId, 'version' => $version],
                [
                    'title' => $settings['form_title'] ?? $settings['title'] ?? $formId,
                    'default_language' => $settings['default_language'] ?? null,
                    'source' => $source,
                    'file_path' => $filePath,
                    'data_source_id' => $dataSourceId,
                    'settings' => $settings,
                    'variables_count' => count(array_filter($parsed['variables'], fn ($v) => ! str_contains($v['base_type'], 'group') && ! str_contains($v['base_type'], 'repeat'))),
                ],
            );

            $formVersion->variables()->delete();
            $formVersion->choices()->delete();

            $now = now();
            foreach (array_chunk($parsed['variables'], 200) as $chunk) {
                FormVariable::insert(array_map(fn ($v) => $v + [
                    'form_version_id' => $formVersion->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $choices = array_merge($parsed['choices'], $this->choicesFromFiles($parsed['variables']));
            foreach (array_chunk($choices, 500) as $chunk) {
                FormChoice::insert(array_map(fn ($c) => [
                    'form_version_id' => $formVersion->id,
                    'list_name' => $c['list_name'],
                    'name' => $c['name'],
                    'label' => $c['label'],
                    'extra' => isset($c['extra']) ? json_encode($c['extra'], JSON_UNESCAPED_UNICODE) : null,
                ], $chunk));
            }

            return $formVersion;
        });

        $formVersion->activate();
        Dictionary::flush();

        return $formVersion;
    }

    /**
     * Charge les choix des « select_*_from_file » depuis les CSV du dossier de paramètres.
     *
     * @return list<array<string, mixed>>
     */
    private function choicesFromFiles(array $variables): array
    {
        $files = collect($variables)
            ->filter(fn ($v) => str_ends_with($v['base_type'], '_from_file') && $v['list_name'])
            ->pluck('list_name')
            ->unique();

        $choices = [];
        foreach ($files as $file) {
            $path = rtrim(config('gavi.settings_path'), '/\\').DIRECTORY_SEPARATOR.$file;
            if (! is_file($path)) {
                continue;
            }
            foreach (CsvReader::read($path) as $row) {
                if (! isset($row['name']) || $row['name'] === '') {
                    continue;
                }
                $choices[] = [
                    'list_name' => $file,
                    'name' => (string) $row['name'],
                    'label' => $row['label'] ?? null,
                    'extra' => array_diff_key($row, ['name' => 1, 'label' => 1]) ?: null,
                ];
            }
        }

        return $choices;
    }
}
