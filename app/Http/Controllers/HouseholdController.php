<?php

namespace App\Http\Controllers;

use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QueryEvent;
use App\Services\Attachments\SubmissionPhotos;
use App\Services\Connectors\ConnectorException;
use App\Services\Dictionary\Dictionary;
use App\Support\Geo;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            'corrections' => DataCorrection::where('submission_uuid', $household->submission_uuid)->latest('id')->get(),
            'trail' => $this->trail($household),
        ] + $this->mapContext($request, $household));
    }

    /**
     * Historique de la soumission (piste d'audit) : réception, mises à jour, queries (affectations, réponses,
     * validations), corrections (propositions, décisions), exclusion. Du plus récent au plus ancien.
     *
     * @return Collection<int, array{at: CarbonInterface, who: ?string, what: string, ref: ?string, url: ?string}>
     */
    private function trail(Household $household)
    {
        $queryIds = DataQuery::withoutGlobalScope('kind')->where('household_id', $household->id)->pluck('id');
        $events = QueryEvent::with('user:id,name')->whereIn('data_query_id', $queryIds)->get()->map(fn (QueryEvent $e) => [
            'at' => $e->created_at,
            'who' => $e->user?->name ?? 'Système',
            'what' => trim(match ($e->type) {
                'assignment' => 'Affectation',
                'correction' => 'Corrections validées',
                'comment' => 'Commentaire',
                default => $e->to_status ? 'Statut : '.(DataQuery::STATUSES[$e->to_status] ?? $e->to_status) : 'Système',
            }.($e->signed ? ' (signée)' : '').($e->comment ? ' — '.$e->comment : '')),
            'ref' => DataQuery::codeFor($e->data_query_id),
            'url' => route('queries.show', $e->data_query_id),
        ]);
        $corrections = DataCorrection::with(['proposer:id,name', 'decider:id,name'])->where('submission_uuid', $household->submission_uuid)->get()
            ->flatMap(fn (DataCorrection $c) => array_filter([
                ['at' => $c->proposed_at, 'who' => $c->proposer?->name ?? 'Système', 'what' => 'Correction proposée : '.$c->variableLabel().' '.$c->displayValue($c->old_value).' → '.$c->displayValue($c->new_value).' ('.$c->reasonLabel().')', 'ref' => 'C-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT), 'url' => null],
                $c->decided_at ? ['at' => $c->decided_at, 'who' => $c->decider?->name ?? 'Système', 'what' => 'Correction '.mb_strtolower($c->statusLabel()).($c->signed ? ' (signée)' : ''), 'ref' => 'C-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT), 'url' => null] : null,
            ]));
        $base = collect([
            $household->submitted_at ? ['at' => $household->submitted_at, 'who' => null, 'what' => 'Soumission envoyée au serveur ODK', 'ref' => null, 'url' => null] : null,
            ['at' => $household->created_at, 'who' => null, 'what' => 'Reçue par la plateforme ('.($household->source_type ?? 'import').')', 'ref' => null, 'url' => null],
            $household->updated_at && $household->updated_at->ne($household->created_at) ? ['at' => $household->updated_at, 'who' => null, 'what' => 'Dernière mise à jour de la base nettoyée', 'ref' => null, 'url' => null] : null,
            $household->excluded_at ? ['at' => $household->excluded_at, 'who' => null, 'what' => 'Exclue de la base nettoyée (doublon)', 'ref' => null, 'url' => null] : null,
        ])->filter();

        return $base->concat($events)->concat($corrections)->sortByDesc(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values();
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
