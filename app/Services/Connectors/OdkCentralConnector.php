<?php

namespace App\Services\Connectors;

use App\Models\FormVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * ODK Central : authentification par session (POST /v1/sessions → jeton Bearer),
 * soumissions via OData, dictionnaire via le XLSForm d'origine.
 */
class OdkCentralConnector extends BaseConnector
{
    public function testConnection(): array
    {
        try {
            $response = $this->authed('get', $this->formPath(), [], ['X-Extended-Metadata' => 'true']);
            if (! $response->successful()) {
                $this->fail($response, 'Formulaire inaccessible');
            }
            $form = $response->json();

            return [
                'ok' => true,
                'message' => sprintf('Connexion réussie au formulaire « %s » (version %s).', $form['name'] ?? $form['xmlFormId'], $form['version'] ?? '—'),
                'details' => [
                    'Formulaire' => $form['xmlFormId'] ?? null,
                    'Version' => $form['version'] ?? null,
                    'Soumissions' => $form['submissions'] ?? null,
                    'Dernière soumission' => $form['lastSubmission'] ?? null,
                ],
            ];
        } catch (ConnectorException|ConnectionException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'details' => []];
        }
    }

    public function fetchSubmissions(?CarbonInterface $since, callable $onPage): int
    {
        $top = (int) config('gavi.http.page_size');
        $skip = 0;
        $total = 0;

        do {
            $query = ['$expand' => '*', '$top' => $top, '$skip' => $skip, '$count' => 'true'];
            if ($since) {
                // Les modifications (edits) mettent à jour updatedAt : on les récupère aussi pour la fermeture automatique des queries.
                $iso = CarbonImmutable::instance($since)->utc()->format('Y-m-d\TH:i:s.v\Z');
                $query['$filter'] = "__system/submissionDate gt {$iso} or __system/updatedAt gt {$iso}";
            }
            $response = $this->authed('get', $this->formPath().'.svc/Submissions', $query);
            if (! $response->successful()) {
                $this->fail($response, 'Récupération des soumissions');
            }

            $rows = $response->json('value') ?? [];
            if ($rows !== []) {
                $onPage(array_map(fn (array $row) => $this->normalizer->fromOdk($row), $rows));
            }
            $total += count($rows);
            $skip += $top;
        } while (count($rows) === $top);

        return $total;
    }

    public function importDictionary(): FormVersion
    {
        // ODK Central ne sert le XLSForm que dans son format d'origine (.xlsx ou .xls).
        foreach (['xlsx', 'xls'] as $extension) {
            $response = $this->authed('get', $this->formPath().'.'.$extension);
            if ($response->status() !== 404) {
                break;
            }
        }
        if ($response->status() === 404) {
            throw new ConnectorException('Ce formulaire a été publié en XML : aucun XLSForm n\'est disponible sur ODK Central. Importez le fichier manuellement.');
        }
        if (! $response->successful()) {
            $this->fail($response, 'Téléchargement du XLSForm');
        }

        $path = $this->storeDownload($response->body(), $extension);

        return $this->dictionaryLoader->loadFile($path, 'api', $this->source->id);
    }

    /**
     * L'API ODK Central est servie à la racine du domaine (/v1/…) : on ignore tout chemin
     * collé depuis l'interface web (ex. /projects/195/forms/x/submissions ou /#/…),
     * sinon nginx sert l'application front et répond 405 aux requêtes POST.
     */
    protected function baseUrl(): string
    {
        $parts = parse_url(trim((string) $this->source->server_url));
        if (! isset($parts['scheme'], $parts['host'])) {
            return parent::baseUrl();
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** Contenu d'une pièce jointe (photo) d'une soumission. */
    public function attachment(string $instanceId, string $filename): string
    {
        $response = $this->authed('get', $this->formPath().'/submissions/'.rawurlencode($instanceId).'/attachments/'.rawurlencode($filename));
        if (! $response->successful()) {
            $this->fail($response, 'Téléchargement de la pièce jointe');
        }

        return $response->body();
    }

    private function formPath(): string
    {
        return sprintf('/v1/projects/%s/forms/%s', rawurlencode((string) $this->source->project_id), rawurlencode((string) $this->source->form_id));
    }

    /** Requête authentifiée ; en cas de 401 le jeton est renouvelé une fois. */
    private function authed(string $method, string $path, array $query = [], array $headers = []): Response
    {
        $response = $this->http()->withToken($this->token())->withHeaders($headers)->send(strtoupper($method), $path, ['query' => $query]);
        if ($response->status() === 401) {
            $response = $this->http()->withToken($this->token(true))->withHeaders($headers)->send(strtoupper($method), $path, ['query' => $query]);
        }

        return $response;
    }

    /** Jeton de session, réutilisé tant qu'il n'expire pas dans les 5 minutes. */
    public function token(bool $refresh = false): string
    {
        $source = $this->source;
        if (! $refresh && $source->auth_token && $source->auth_token_expires_at?->isAfter(now()->addMinutes(5))) {
            return $source->auth_token;
        }

        $response = $this->http()->post('/v1/sessions', [
            'email' => $source->credential('email'),
            'password' => $source->credential('password'),
        ]);
        if (! $response->successful() || ! $response->json('token')) {
            $this->fail($response, 'Authentification ODK Central');
        }

        $source->forceFill([
            'auth_token' => $response->json('token'),
            'auth_token_expires_at' => CarbonImmutable::parse($response->json('expiresAt') ?? now()->addDay()),
        ])->save();

        return $response->json('token');
    }
}
