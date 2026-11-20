<?php

namespace App\Http\Controllers;

use App\Models\HealthZone;
use App\Services\Dashboard\RemainingVillages;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Villages restants (aucun ménage encore visité) et binômes concernés : administrateur et PI pour toutes les zones
 * (filtre par zone), superviseurs pour leur zone ou leur commune. Les binômes ont leur onglet dans leur espace.
 */
class RemainingVillagesController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $scope = $user->geographicScope();
        $zoneId = match ($scope['column'] ?? null) {
            'health_zone_id' => (int) $scope['id'],
            'commune_id' => $user->supervisedZoneId(),
            default => $request->integer('health_zone_id') ?: null,
        };
        $remaining = new RemainingVillages($zoneId, ($scope['column'] ?? null) === 'commune_id' ? (int) $scope['id'] : null);

        return view('remaining-villages.index', [
            'remaining' => $remaining->remaining(),
            'summary' => $remaining->summary(),
            'zoneId' => $zoneId,
            'locked' => $scope !== null,
            'scopeLabel' => match ($scope['column'] ?? null) {
                'commune_id' => 'Commune de '.$user->commune?->name,
                'health_zone_id' => 'Zone sanitaire de '.$user->healthZone?->name,
                default => null,
            },
            'zones' => HealthZone::whereHas('communes')->orderBy('name')->get(['id', 'name']),
            'showInvestigatorTabs' => $user->can('view pi dashboard'),
        ]);
    }
}
