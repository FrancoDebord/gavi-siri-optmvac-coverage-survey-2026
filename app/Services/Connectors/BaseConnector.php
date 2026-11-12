<?php

namespace App\Services\Connectors;

use App\Models\DataSource;
use App\Services\Dictionary\DictionaryLoader;
use App\Services\Import\SubmissionNormalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

abstract class BaseConnector implements DataSourceConnector
{
    public function __construct(
        protected readonly DataSource $source,
        protected readonly SubmissionNormalizer $normalizer,
        protected readonly DictionaryLoader $dictionaryLoader,
    ) {}

    protected function baseUrl(): string
    {
        return rtrim($this->source->server_url, '/');
    }

    protected function http(): PendingRequest
    {
        $this->throttle();

        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout(config('gavi.http.timeout'))
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    /** Limite le nombre d'appels par minute vers un même serveur. */
    protected function throttle(): void
    {
        $key = 'connector:'.$this->source->id;
        $max = (int) config('gavi.http.rate_limit_per_minute');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            sleep(min(60, RateLimiter::availableIn($key)));
        }
        RateLimiter::hit($key, 60);
    }

    protected function fail(Response $response, string $context): never
    {
        $body = $response->json();
        $detail = is_array($body) ? ($body['message'] ?? $body['detail'] ?? json_encode($body, JSON_UNESCAPED_UNICODE)) : mb_substr($response->body(), 0, 300);

        throw new ConnectorException("{$context} : HTTP {$response->status()} – {$detail}");
    }

    /** Stocke un fichier téléchargé dans storage/app/private/dictionaries. */
    protected function storeDownload(string $content, string $extension): string
    {
        $directory = storage_path('app/private/dictionaries');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.DIRECTORY_SEPARATOR.'source-'.$this->source->id.'-'.now()->format('Ymd-His').'.'.$extension;
        file_put_contents($path, $content);

        return $path;
    }
}
