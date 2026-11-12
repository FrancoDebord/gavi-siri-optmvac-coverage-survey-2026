<?php

namespace App\Http\Controllers;

use App\Http\Requests\DataSourceRequest;
use App\Jobs\SyncDataSourceJob;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Services\Connectors\ConnectorFactory;
use App\Services\Dictionary\VariableMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DataSourceController extends Controller
{
    public function index(): View
    {
        return view('sources.index', [
            'sources' => DataSource::with('latestSyncLog')->withCount('syncLogs')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('sources.form', ['source' => new DataSource(['type' => DataSource::TYPE_ODK, 'sync_interval_minutes' => 30, 'is_active' => true])]);
    }

    public function store(DataSourceRequest $request): RedirectResponse
    {
        $source = DataSource::create($this->attributes($request, new DataSource));
        AuditLog::record('source.created', $source, ['nom' => $source->name, 'type' => $source->type]);

        return redirect()->route('sources.show', $source)->with('success', 'Source créée. Testez la connexion avant d\'activer la synchronisation.');
    }

    public function show(DataSource $source): View
    {
        return view('sources.show', [
            'source' => $source,
            'logs' => $source->syncLogs()->with('user')->latest('started_at')->paginate(20),
        ]);
    }

    public function edit(DataSource $source): View
    {
        return view('sources.form', ['source' => $source]);
    }

    public function update(DataSourceRequest $request, DataSource $source): RedirectResponse
    {
        $source->update($this->attributes($request, $source));
        AuditLog::record('source.updated', $source, ['nom' => $source->name]);

        return redirect()->route('sources.show', $source)->with('success', 'Source mise à jour.');
    }

    public function destroy(DataSource $source): RedirectResponse
    {
        AuditLog::record('source.deleted', $source, ['nom' => $source->name]);
        $source->delete();

        return redirect()->route('sources.index')->with('success', 'Source supprimée (les soumissions déjà importées sont conservées).');
    }

    public function test(DataSource $source, ConnectorFactory $connectors): JsonResponse
    {
        $result = $connectors->make($source)->testConnection();
        AuditLog::record('source.tested', $source, ['succes' => $result['ok']]);

        return response()->json($result);
    }

    public function sync(Request $request, DataSource $source): RedirectResponse
    {
        SyncDataSourceJob::dispatch($source->id, 'manual', $request->user()->id, $request->boolean('full'));
        AuditLog::record('source.sync', $source, ['complete' => $request->boolean('full')]);

        return back()->with('success', 'Synchronisation lancée en arrière-plan. Le journal sera mis à jour à la fin du traitement.');
    }

    /** Récupère le XLSForm (ODK) ou le contenu du formulaire (Kobo) et l'active comme dictionnaire. */
    public function dictionary(DataSource $source, ConnectorFactory $connectors): RedirectResponse
    {
        try {
            $formVersion = $connectors->make($source)->importDictionary();
            VariableMap::seedDefaults();
            AuditLog::record('dictionary.imported_api', $formVersion, ['source' => $source->name]);

            return redirect()->route('dictionary.index')->with('success', "Dictionnaire {$formVersion->form_id} v{$formVersion->version} importé depuis {$source->name} et activé.");
        } catch (Throwable $e) {
            return back()->with('danger', 'Import du dictionnaire impossible : '.$e->getMessage());
        }
    }

    private function attributes(DataSourceRequest $request, DataSource $source): array
    {
        $data = $request->validated();
        $credentials = $source->credentials ?? [];
        if ($data['type'] === DataSource::TYPE_ODK) {
            $credentials = ['email' => $data['email'], 'password' => ($data['password'] ?? null) ?: ($credentials['password'] ?? null)];
        } else {
            $credentials = ['token' => ($data['token'] ?? null) ?: ($credentials['token'] ?? null)];
        }

        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'server_url' => rtrim($data['server_url'], '/'),
            'credentials' => $credentials,
            'project_id' => $data['type'] === DataSource::TYPE_ODK ? $data['project_id'] : null,
            'form_id' => $data['type'] === DataSource::TYPE_ODK ? $data['form_id'] : null,
            'asset_uid' => $data['type'] === DataSource::TYPE_KOBO ? $data['asset_uid'] : null,
            'sync_interval_minutes' => $data['sync_interval_minutes'],
            'is_active' => $request->boolean('is_active'),
            // Identifiants modifiés : le jeton de session ODK en cache n'est plus valable.
            'auth_token' => null,
            'auth_token_expires_at' => null,
        ];
    }
}
