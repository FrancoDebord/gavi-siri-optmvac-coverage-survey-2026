<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Services\Attachments\SubmissionPhotos;
use App\Services\Connectors\ConnectorException;
use App\Services\Dictionary\Dictionary;
use App\Support\Geo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class HouseholdController extends Controller
{
    public function show(Request $request, Household $household): View
    {
        $this->authorize('view', $household);
        $household->load(['caregivers.children', 'healthZone', 'commune', 'village', 'cluster.village', 'cluster.subClusters', 'collector.team', 'dataSource', 'queries' => fn ($q) => $q->latest('id')]);

        return view('households.show', [
            'household' => $household,
            'dictionary' => Dictionary::current(),
        ] + $this->mapContext($request, $household));
    }

    /**
     * Carte de la soumission : le ménage, les contours de sa grappe et de ses sous-grappes, les autres ménages
     * de la grappe (dans le périmètre de l'utilisateur) et la position du ménage par rapport aux contours.
     */
    private function mapContext(Request $request, Household $household): array
    {
        $cluster = $household->cluster;
        $subCluster = $cluster?->subClusters->firstWhere('number', $household->sub_cluster);
        $position = null;
        if ($household->latitude !== null && $household->longitude !== null) {
            $position = [
                'cluster' => $cluster?->boundary ? Geo::locate($household->latitude, $household->longitude, $cluster->boundary) : null,
                'sub_cluster' => $subCluster?->boundary ? Geo::locate($household->latitude, $household->longitude, $subCluster->boundary) : null,
            ];
        }

        $neighbours = $cluster
            ? Household::visibleTo($request->user())->where('cluster_id', $cluster->id)->whereKeyNot($household->id)
                ->whereNotNull('latitude')->whereNotNull('longitude')->limit(2000)
                ->get(['id', 'latitude', 'longitude', 'household_code', 'consented', 'sub_cluster'])
                ->map(fn ($h) => ['lat' => $h->latitude, 'lng' => $h->longitude, 'consented' => $h->consented, 'code' => $h->household_code,
                    'sub_cluster' => $h->sub_cluster, 'url' => route('households.show', $h)])->values()->all()
            : [];

        return [
            'position' => $position,
            'subCluster' => $subCluster,
            'mapData' => [
                'household' => $household->latitude !== null ? [
                    'lat' => $household->latitude, 'lng' => $household->longitude, 'consented' => $household->consented,
                    'code' => $household->household_code, 'accuracy' => $household->gps_accuracy,
                ] : null,
                'neighbours' => $neighbours,
                'boundaries' => $cluster?->hasMapBoundary() ? [$cluster->mapBoundary()] : [],
            ],
        ];
    }

    /** Photo prise dans le formulaire (carnet, acte de naissance), servie depuis le cache local ou ODK Central. */
    public function photo(Household $household, string $filename, SubmissionPhotos $photos): BinaryFileResponse
    {
        $this->authorize('view', $household);
        abort_unless($photos->belongsTo($household, $filename), 404);

        try {
            $path = $photos->path($household, $filename);
        } catch (ConnectorException|ConnectionException $e) {
            abort(502, $e->getMessage());
        }

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400']);
    }
}
