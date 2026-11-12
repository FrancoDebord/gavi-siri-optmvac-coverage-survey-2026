<?php

namespace App\Services\Connectors;

use App\Models\FormVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;

/**
 * KoboToolbox (API v2) : authentification par jeton (Authorization: Token xxx).
 */
class KoboConnector extends BaseConnector
{
    protected function http(): PendingRequest
    {
        return parent::http()->withHeaders(['Authorization' => 'Token '.$this->source->credential('token')]);
    }

    public function testConnection(): array
    {
        try {
            $response = $this->http()->get($this->assetPath(), ['format' => 'json']);
            if (! $response->successful()) {
                $this->fail($response, 'Projet inaccessible');
            }
            $asset = $response->json();

            return [
                'ok' => true,
                'message' => sprintf('Connexion réussie au projet « %s ».', $asset['name'] ?? $this->source->asset_uid),
                'details' => [
                    'Projet' => $asset['name'] ?? null,
                    'Déployé' => ($asset['has_deployment'] ?? false) ? 'oui' : 'non',
                    'Soumissions' => $asset['deployment__submission_count'] ?? null,
                    'Version' => $asset['deployed_version_id'] ?? $asset['version_id'] ?? null,
                ],
            ];
        } catch (ConnectorException|ConnectionException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    public function fetchSubmissions(?CarbonInterface $since, callable $onPage): int
    {
        $limit = (int) config('gavi.http.page_size');
        $start = 0;
        $total = 0;

        do {
            $query = ['limit' => $limit, 'start' => $start, 'sort' => '{"_submission_time":1}'];
            if ($since) {
                $query['query'] = json_encode(['_submission_time' => ['$gt' => CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s')]]);
            }
            $response = $this->http()->get($this->assetPath().'data.json', $query);
            if (! $response->successful()) {
                $this->fail($response, 'Récupération des soumissions');
            }

            $rows = $response->json('results') ?? [];
            if ($rows !== []) {
                $onPage(array_map(fn (array $row) => $this->normalizer->fromKobo($row), $rows));
            }
            $total += count($rows);
            $start += $limit;
        } while ($response->json('next') && $rows !== []);

        return $total;
    }

    public function importDictionary(): FormVersion
    {
        $response = $this->http()->get($this->assetPath(), ['format' => 'json']);
        if (! $response->successful()) {
            $this->fail($response, 'Lecture du formulaire');
        }
        $content = $response->json('content') ?? [];
        if (empty($content['survey'])) {
            throw new ConnectorException('Le contenu du formulaire (content.survey) est vide.');
        }
        $content['settings'] = array_merge(
            ['form_id' => $this->source->asset_uid, 'version' => $response->json('version_id'), 'form_title' => $response->json('name')],
            array_filter($content['settings'] ?? []),
        );

        return $this->dictionaryLoader->loadKoboContent($content, $this->source->id);
    }

    private function assetPath(): string
    {
        return '/api/v2/assets/'.rawurlencode((string) $this->source->asset_uid).'/';
    }
}
