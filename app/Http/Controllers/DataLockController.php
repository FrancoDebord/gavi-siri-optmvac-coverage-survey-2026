<?php

namespace App\Http\Controllers;

use App\Jobs\BuildDataExportJob;
use App\Models\AuditLog;
use App\Models\DataCorrection;
use App\Models\DataExport;
use App\Models\DataLock;
use App\Models\DataQuery;
use App\Models\Household;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Gel de la base (administrateur) : chaque gel ouvre une version numérotée, archivée automatiquement par un paquet
 * d'archivage (empreinte SHA-256) ; le dégel exige un motif. Gel et dégel sont signés par mot de passe et tracés.
 */
class DataLockController extends Controller
{
    public function index(): View
    {
        return view('locks.index', [
            'current' => DataLock::current(),
            'locks' => DataLock::with(['user:id,name', 'export'])->latest('id')->get(),
            'pending' => [
                'unresolved' => DataQuery::whereIn('status', DataQuery::UNRESOLVED)->count(),
                'to_validate' => DataQuery::whereIn('status', [...DataQuery::RESPONSES, ...DataQuery::AWAITING_FINAL])->count(),
                'proposed' => DataCorrection::where('status', DataCorrection::STATUS_PROPOSED)->count(),
                'conflicts' => DataCorrection::where('status', DataCorrection::STATUS_CONFLICT)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'password' => ['required', 'string'],
        ], [], ['reason' => 'motif', 'password' => 'mot de passe']);
        if (! Hash::check($data['password'], $request->user()->password)) {
            return back()->withErrors(['password' => 'Mot de passe incorrect : signature refusée.'])->withInput($request->except('password'));
        }

        $current = DataLock::current();
        $lock = DB::transaction(function () use ($current, $data, $request) {
            if ($current) {
                return DataLock::create(['version' => $current->version, 'action' => DataLock::UNFREEZE, 'reason' => $data['reason'], 'user_id' => $request->user()->id, 'signed' => true]);
            }
            // Gel : nouvelle version, état de la base et paquet d'archivage de référence.
            $export = DataExport::create(['type' => DataExport::TYPE_ARCHIVE, 'status' => DataExport::STATUS_PENDING, 'requested_by' => $request->user()->id]);

            return DataLock::create([
                'version' => (int) DataLock::max('version') + 1,
                'action' => DataLock::FREEZE,
                'reason' => $data['reason'],
                'user_id' => $request->user()->id,
                'signed' => true,
                'data_export_id' => $export->id,
                'stats' => [
                    'menages' => Household::count(),
                    'menages_exclus' => Household::withoutGlobalScope('included')->whereNotNull('excluded_at')->count(),
                    'corrections_validees' => DataCorrection::where('status', DataCorrection::STATUS_VALIDATED)->count(),
                    'queries_non_resolues' => DataQuery::whereIn('status', DataQuery::UNRESOLVED)->count(),
                    'queries_a_valider' => DataQuery::whereIn('status', [...DataQuery::RESPONSES, ...DataQuery::AWAITING_FINAL])->count(),
                ],
            ]);
        });
        DataLock::forget();
        AuditLog::record($lock->action === DataLock::FREEZE ? 'data.gel' : 'data.degel', $lock, ['version' => $lock->label(), 'motif' => $lock->reason]);
        if ($lock->data_export_id) {
            BuildDataExportJob::dispatch($lock->data_export_id);
        }

        return redirect()->route('locks.index')->with('success', $lock->action === DataLock::FREEZE
            ? "Base gelée : version {$lock->label()}. Le paquet d'archivage de la version est en cours de génération."
            : "Base dégelée (fin de la version {$lock->label()}). Les corrections et les synchronisations reprennent.");
    }
}
