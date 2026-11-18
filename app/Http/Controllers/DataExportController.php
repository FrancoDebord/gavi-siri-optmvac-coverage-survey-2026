<?php

namespace App\Http\Controllers;

use App\Jobs\BuildDataExportJob;
use App\Models\AuditLog;
use App\Models\DataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Exports des bases (administrateur) : base brute, base nettoyée, paquet d'archivage, copie SQL chiffrée.
 * Génération en arrière-plan ; chaque demande et chaque téléchargement sont tracés au journal d'audit.
 */
class DataExportController extends Controller
{
    public function index(): View
    {
        return view('exports.index', [
            'exports' => DataExport::with('requester:id,name')->latest('id')->paginate(20),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DataExport::TYPES))],
            // Copie SQL : mot de passe de chiffrement (12 caractères minimum) et mot de passe du compte (signature).
            'zip_password' => ['required_if:type,'.DataExport::TYPE_SQL, 'nullable', 'string', 'min:12', 'max:128', 'confirmed'],
            'account_password' => ['required_if:type,'.DataExport::TYPE_SQL, 'nullable', 'string'],
        ], [], ['zip_password' => 'mot de passe de chiffrement', 'account_password' => 'mot de passe du compte']);

        if ($data['type'] === DataExport::TYPE_SQL && ! Hash::check((string) $data['account_password'], $request->user()->password)) {
            return back()->withErrors(['account_password' => 'Mot de passe du compte incorrect.']);
        }
        if (DataExport::whereIn('status', [DataExport::STATUS_PENDING, DataExport::STATUS_RUNNING])->where('type', $data['type'])->exists()) {
            return back()->withErrors(['type' => 'Un export de ce type est déjà en cours de génération.']);
        }

        $export = DataExport::create(['type' => $data['type'], 'status' => DataExport::STATUS_PENDING, 'requested_by' => $request->user()->id]);
        AuditLog::record('export.base_demande', $export, ['type' => $export->type]);
        BuildDataExportJob::dispatch($export->id, $data['type'] === DataExport::TYPE_SQL ? $data['zip_password'] : null);

        return redirect()->route('exports.index')->with('success', $export->typeLabel().' : génération lancée. Le fichier apparaîtra dans la liste.');
    }

    public function download(DataExport $export): BinaryFileResponse|RedirectResponse
    {
        if (! $export->isDownloadable()) {
            return back()->withErrors(['type' => 'Fichier indisponible (en cours, échoué ou expiré).']);
        }
        $export->increment('download_count');
        AuditLog::record('export.base_telechargement', $export, ['type' => $export->type, 'fichier' => $export->file_name, 'sha256' => $export->sha256]);

        return response()->download($export->file_path, $export->file_name);
    }
}
