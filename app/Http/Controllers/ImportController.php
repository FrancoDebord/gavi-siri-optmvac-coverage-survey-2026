<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportDataRequest;
use App\Jobs\ImportSubmissionsJob;
use App\Models\AuditLog;
use App\Models\Cluster;
use App\Models\ImportBatch;
use App\Services\Import\ClusterBoundaryImporter;
use App\Services\Import\ClusterListImporter;
use App\Services\Import\ReferenceDataLoader;
use App\Support\DashboardCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ImportController extends Controller
{
    private const STATUS = [
        'pending' => ['En attente', 'secondary'],
        'running' => ['En cours', 'primary'],
        'done' => ['Terminé', 'success'],
        'failed' => ['Échec', 'danger'],
    ];

    public function index(): View
    {
        return view('imports.index', [
            'batches' => ImportBatch::with('user')->latest('id')->paginate(15),
            'status' => self::STATUS,
            'clusters' => Cluster::whereNotNull('target_households')->count(),
            'boundaries' => Cache::get(ClusterBoundaryImporter::REPORT_CACHE_KEY),
            'boundaryDirectory' => app(ClusterBoundaryImporter::class)->directory(),
        ]);
    }

    /** Contours GPS des grappes : réimport du dossier settings/points_gps. */
    public function importBoundaries(Request $request, ClusterBoundaryImporter $importer): RedirectResponse|JsonResponse
    {
        $progress = $this->boundaryProgressReporter($request);
        $report = $importer->run($progress);
        AuditLog::record('import.contours', null, ['grappes' => $report['clusters'], 'sous_grappes' => $report['sub_clusters'], 'points' => $report['points']]);
        $problems = collect($report['files'])->filter(fn ($f) => $f['error'] || $f['warnings'])->count();
        $message = sprintf('Contours importés : %d grappe(s), %d sous-grappe(s), %d point(s).', $report['clusters'], $report['sub_clusters'], $report['points']);

        return $problems
            ? $this->boundaryResponse($request, $progress, 'warning', $message.' '.$problems.' fichier(s) à vérifier : voir les remarques dans le tableau des contours.')
            : $this->boundaryResponse($request, $progress, 'success', $message);
    }

    /** Dépôt de nouveaux relevés GPS (plusieurs fichiers), puis import de tous les contours. */
    public function uploadBoundaries(Request $request, ClusterBoundaryImporter $importer): RedirectResponse|JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:50'],
            'files.*' => ['file', 'max:1024', 'extensions:csv,txt'],
        ], [], ['files' => 'fichiers', 'files.*' => 'fichier']);

        $progress = $this->boundaryProgressReporter($request);
        $uploads = $request->file('files');
        $saved = $rejected = [];
        foreach ($uploads as $i => $file) {
            $name = basename($file->getClientOriginalName());
            $progress('depot', $i, count($uploads), $name);
            if (! $importer->isValidFileName($name)) {
                $rejected[] = $name;

                continue;
            }
            $existed = in_array($name, $importer->files(), true);
            $file->move($importer->directory(), $name);
            $saved[] = $name.($existed ? ' (remplacé)' : '');
        }
        if (! $saved) {
            return $this->boundaryResponse($request, $progress, 'danger', 'Aucun fichier déposé : nom non conforme ('.implode(', ', $rejected).'). Format attendu : '.ClusterBoundaryImporter::FILE_FORMAT.', par exemple DAG-ASS-01-06-11-2026.csv ou TCH-KPR-06-H01-09-11-2026.csv.');
        }

        $report = $importer->run($progress);
        AuditLog::record('import.contours.depot', null, ['fichiers' => $saved, 'refuses' => $rejected]);
        $files = collect($report['files'])->whereIn('file', array_map(fn ($n) => preg_replace('/ \(remplacé\)$/', '', $n), $saved));
        $problems = $files->filter(fn ($f) => $f['error'] || $f['warnings'])->pluck('file');
        $message = count($saved).' relevé(s) déposé(s) : '.implode(', ', $saved).'.'
            .($rejected ? ' Refusé(s), nom non conforme : '.implode(', ', $rejected).'.' : '')
            .($problems->isNotEmpty() ? ' À vérifier : '.$problems->implode(', ').' (voir les remarques).' : '');

        return $this->boundaryResponse($request, $progress, $rejected || $problems->isNotEmpty() ? 'warning' : 'success', $message);
    }

    /** Avancement d'un import de contours en cours (lu par la page pendant le traitement). */
    public function boundaryProgress(Request $request, string $token): JsonResponse
    {
        return response()->json(Cache::get(self::boundaryProgressKey($request->user()->id, $token)) ?? ['step' => 'attente']);
    }

    private static function boundaryProgressKey(int $userId, string $token): string
    {
        return "gavi.boundaries.progress.{$userId}.{$token}";
    }

    /**
     * Publie l'avancement sous le jeton envoyé par la page (progress_token, UUID) ; la clé inclut l'utilisateur,
     * qui seul peut la lire. Sans jeton, l'avancement n'est pas publié.
     *
     * @return callable(string, int, int, ?string): void
     */
    private function boundaryProgressReporter(Request $request): callable
    {
        $token = $request->input('progress_token');
        if (! is_string($token) || ! Str::isUuid($token)) {
            return static fn () => null;
        }
        $key = self::boundaryProgressKey($request->user()->id, $token);

        return static function (string $step, int $done = 0, int $total = 0, ?string $detail = null) use ($key) {
            Cache::put($key, ['step' => $step, 'done' => $done, 'total' => $total, 'detail' => $detail], now()->addMinutes(10));
        };
    }

    /** Message de fin : redirection classique, ou JSON (page avec barre de progression) qui recharge la page. */
    private function boundaryResponse(Request $request, callable $progress, string $level, string $message): RedirectResponse|JsonResponse
    {
        $progress('termine', 1, 1, null);
        if (! $request->expectsJson()) {
            return back()->with($level, $message);
        }
        session()->flash($level, $message);

        return response()->json(['redirect' => route('imports.index')]);
    }

    /** Suppression d'un relevé du dossier (le contour qu'il fournissait est retiré ou remplacé par un relevé plus ancien). */
    public function destroyBoundary(string $file, ClusterBoundaryImporter $importer): RedirectResponse
    {
        abort_unless(in_array($file, $importer->files(), true), 404);
        unlink($importer->directory().DIRECTORY_SEPARATOR.$file);
        $importer->run();
        AuditLog::record('import.contours.suppression', null, ['fichier' => $file]);

        return back()->with('success', "Relevé {$file} supprimé ; contours mis à jour.");
    }

    /** Tous les contours en GeoJSON (QGIS, Google Earth…). */
    public function boundaryGeoJson(ClusterBoundaryImporter $importer): Response
    {
        return response(json_encode($importer->geoJson(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 200, [
            'Content-Type' => 'application/geo+json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="contours_grappes_'.now()->format('Ymd').'.geojson"',
        ]);
    }

    /** Tout ensemble : compilation des points, contours GeoJSON et relevés d'origine dans une archive ZIP. */
    public function boundaryArchive(ClusterBoundaryImporter $importer): BinaryFileResponse
    {
        AuditLog::record('export.contours', null, ['fichiers' => count($importer->files())]);

        return response()->download($importer->exportArchive())->deleteFileAfterSend();
    }

    /** Compilation de tous les points GPS (CSV, séparateur « ; »). */
    public function boundaryCompilation(ClusterBoundaryImporter $importer): BinaryFileResponse
    {
        if (! is_file($importer->compilationPath())) {
            $importer->run();
        }

        return response()->download($importer->compilationPath(), 'compilation_points_gps_'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeData(ImportDataRequest $request): RedirectResponse
    {
        $files = [$this->store($request->file('main_file'))];
        foreach ($request->file('repeat_files', []) as $file) {
            $files[] = $this->store($file);
        }

        $batch = ImportBatch::create(['user_id' => $request->user()->id, 'type' => 'data', 'status' => 'pending', 'files' => $files, 'message' => 'En attente du traitement…']);
        ImportSubmissionsJob::dispatch($batch->id);
        AuditLog::record('import.data', $batch, ['fichiers' => array_column($files, 'name')]);

        return redirect()->route('imports.index')->with('success', 'Import mis en file d\'attente. La progression s\'affiche ci-dessous.');
    }

    public function storeClusters(Request $request, ClusterListImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], [], ['file' => 'liste des grappes']);
        try {
            $result = $importer->import($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('danger', 'Import des grappes impossible : '.$e->getMessage());
        }
        ImportBatch::create(['user_id' => $request->user()->id, 'type' => 'clusters', 'status' => 'done', 'total' => array_sum($result), 'processed' => array_sum($result),
            'new_count' => $result['created'], 'updated_count' => $result['updated'], 'files' => [['name' => $request->file('file')->getClientOriginalName()]],
            'message' => "Grappes : {$result['created']} créée(s), {$result['updated']} mise(s) à jour, {$result['skipped']} ignorée(s)."]);
        AuditLog::record('import.clusters', null, $result);
        DashboardCache::bump();

        return back()->with('success', "Liste des grappes importée : {$result['created']} créée(s), {$result['updated']} mise(s) à jour.");
    }

    public function reloadReferences(ReferenceDataLoader $loader): RedirectResponse
    {
        $counts = $loader->load();
        AuditLog::record('import.references', null, $counts);
        DashboardCache::bump();

        $message = sprintf('Référentiel rechargé : %d zone(s), %d commune(s), %d village(s) dont %d avec code à 3 lettres, %d agent(s) en %d binôme(s).', $counts['zones'], $counts['communes'], $counts['villages'], $counts['codes_villages'], $counts['enqueteurs'], $counts['binomes']);
        $warnings = array_filter([
            $loader->unmatchedVillageCodes ? 'Codes villages sans correspondance : '.implode(' ; ', $loader->unmatchedVillageCodes).'.' : null,
            $loader->unmatchedFrameVillages ? 'Villages de la base de sondage non rattachés : '.implode(' ; ', $loader->unmatchedFrameVillages).'.' : null,
        ]);
        if ($warnings) {
            return back()->with('warning', $message.' '.implode(' ', $warnings));
        }

        return back()->with('success', $message);
    }

    public function status(ImportBatch $batch): JsonResponse
    {
        [$label, $color] = self::STATUS[$batch->status] ?? [$batch->status, 'secondary'];

        return response()->json([
            'status' => $batch->status,
            'status_label' => $label,
            'status_color' => $color,
            'progress' => $batch->progress(),
            'processed' => $batch->processed,
            'total' => $batch->total,
            'message' => $batch->message,
        ]);
    }

    public function clusterTemplate(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['code', 'nom', 'village', 'cible_menages', 'cible_enfants', 'latitude', 'longitude'], ',', '"', '\\');
            foreach (Cluster::with('village')->orderBy('code')->limit(500)->get() as $cluster) {
                fputcsv($out, [$cluster->code, $cluster->name, $cluster->village?->code, $cluster->target_households, $cluster->target_children, $cluster->latitude, $cluster->longitude], ',', '"', '\\');
            }
            fclose($out);
        }, 'modele-grappes.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{path: string, name: string} */
    private function store(UploadedFile $file): array
    {
        $name = $file->getClientOriginalName();
        $stored = $file->storeAs('imports', now()->format('Ymd-His').'-'.uniqid().'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $name));

        return ['path' => storage_path('app/private/'.$stored), 'name' => $name];
    }
}
