<?php

namespace App\Http\Controllers;

use App\Models\AgentDocument;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\DocumentSpace;
use App\Models\HealthZone;
use App\Models\PhotoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Gestion des espaces de factures et rapports d'activité des agents (administrateur, investigateur principal) :
 * création, lien public et code PIN à partager, suivi par agent (qui a déposé quoi), validation ou demande de
 * correction, consultation et téléchargement des documents (un par un ou en ZIP, un dossier par agent).
 */
class DocumentSpaceController extends Controller
{
    public function index(): View
    {
        return view('documents.index', [
            'spaces' => DocumentSpace::withCount([
                'documents',
                'documents as invoices_count' => fn ($q) => $q->where('type', 'facture'),
                'documents as reports_count' => fn ($q) => $q->where('type', 'rapport'),
            ])->withMax('documents', 'created_at')->with('creator:id,name')->latest('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $space = DocumentSpace::create($this->validated($request) + ['created_by' => $request->user()->id]);
        AuditLog::record('documents.espace_cree', $space, ['nom' => $space->name]);

        return redirect()->route('document-spaces.show', $space)->with('success', 'Espace créé : partagez le lien et le code PIN aux agents.');
    }

    public function show(Request $request, DocumentSpace $space): View
    {
        $filters = $this->filters($request);
        $documents = $this->filtered($space, $filters)->with(['collector:id,code,name', 'team:id,code', 'reviewer:id,name'])
            ->latest('id')->paginate(50)->withQueryString();

        // Suivi par agent (espace de l'enquête) : dernier document de chaque type et nombre de fichiers.
        $tracking = collect();
        if ($space->linked_to_survey) {
            $byAgent = $space->documents()->whereNotNull('collector_id')->orderBy('id')
                ->get(['id', 'collector_id', 'type', 'status', 'created_at'])->groupBy('collector_id');
            $tracking = Collector::with(['team:id,code', 'healthZone:id,name'])
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $byAgent->keys()))
                ->when($filters['health_zone_id'], fn ($q, $zone) => $q->where('health_zone_id', $zone))
                ->orderBy('name')->get(['id', 'code', 'name', 'team_id', 'health_zone_id'])
                ->map(function (Collector $collector) use ($byAgent) {
                    $docs = $byAgent->get($collector->id, collect());
                    $row = ['collector' => $collector];
                    foreach (array_keys(AgentDocument::TYPES) as $type) {
                        $ofType = $docs->where('type', $type);
                        $row[$type] = $ofType->isEmpty() ? null : ['count' => $ofType->count(), 'status' => $ofType->last()->status, 'at' => $ofType->last()->created_at];
                    }

                    return $row;
                });
            if ($filters['incomplets']) {
                $tracking = $tracking->filter(fn ($row) => ! $row['facture'] || ! $row['rapport'] || $row['facture']['status'] === AgentDocument::STATUS_REDO || $row['rapport']['status'] === AgentDocument::STATUS_REDO);
            }
        }

        return view('documents.show', [
            'space' => $space,
            'documents' => $documents,
            'filters' => $filters,
            'tracking' => $tracking->values(),
            'zones' => $space->linked_to_survey ? HealthZone::orderBy('name')->get(['id', 'name']) : collect(),
            'agents' => $space->linked_to_survey
                ? Collector::whereIn('id', $space->documents()->whereNotNull('collector_id')->select('collector_id'))->orderBy('name')->get(['id', 'code', 'name'])
                : collect(),
            'total' => $space->documents()->count(),
            'totalSize' => (int) $space->documents()->sum('size'),
        ]);
    }

    public function update(Request $request, DocumentSpace $space): RedirectResponse
    {
        $space->update($this->validated($request) + ['active' => $request->boolean('active')]);
        AuditLog::record('documents.espace_modifie', $space, ['actif' => $space->active]);

        return back()->with('success', 'Espace mis à jour.');
    }

    /** Nouveau lien ou nouveau PIN : l'ancien ne fonctionne plus (appareils à déverrouiller de nouveau). */
    public function regenerate(Request $request, DocumentSpace $space): RedirectResponse
    {
        $what = $request->validate(['what' => ['required', 'in:lien,pin']])['what'];
        $space->forceFill($what === 'lien' ? ['token' => PhotoProject::newToken()] : ['pin' => PhotoProject::newPin()])->save();
        AuditLog::record('documents.'.($what === 'lien' ? 'lien' : 'pin').'_renouvele', $space);

        return back()->with('success', $what === 'lien' ? 'Nouveau lien créé : l\'ancien ne fonctionne plus.' : 'Nouveau code PIN créé : l\'ancien ne fonctionne plus.');
    }

    public function destroy(DocumentSpace $space): RedirectResponse
    {
        AuditLog::record('documents.espace_supprime', null, ['nom' => $space->name, 'documents' => $space->documents()->count()]);
        $space->delete();
        Storage::disk(AgentDocument::DISK)->deleteDirectory('agent-documents/'.$space->id);

        return redirect()->route('document-spaces.index')->with('success', 'Espace supprimé avec ses documents.');
    }

    /** Validation d'un document, ou demande de correction (motif obligatoire, l'agent redépose un fichier). */
    public function review(Request $request, DocumentSpace $space, AgentDocument $document): RedirectResponse
    {
        abort_unless($document->document_space_id === $space->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(AgentDocument::STATUSES))],
            'review_note' => ['nullable', 'string', 'max:500', Rule::requiredIf($request->input('status') === AgentDocument::STATUS_REDO)],
        ], ['review_note.required' => 'Indiquez ce qu\'il faut corriger.'], ['review_note' => 'motif']);

        $document->update([
            'status' => $data['status'],
            'review_note' => $data['review_note'] ?? null,
            'reviewed_by' => $data['status'] === AgentDocument::STATUS_RECEIVED ? null : $request->user()->id,
            'reviewed_at' => $data['status'] === AgentDocument::STATUS_RECEIVED ? null : now(),
        ]);
        AuditLog::record('documents.document_'.$data['status'], $space, ['document' => $document->id, 'agent' => $document->authorLabel(), 'type' => $document->type]);

        return back()->with('success', 'Document « '.$document->original_name.' » : '.mb_strtolower($document->statusLabel()).'.');
    }

    public function destroyDocument(DocumentSpace $space, AgentDocument $document): RedirectResponse
    {
        abort_unless($document->document_space_id === $space->id, 404);
        AuditLog::record('documents.document_supprime', $space, ['document' => $document->id, 'fichier' => $document->original_name, 'agent' => $document->authorLabel()]);
        $document->delete();

        return back()->with('success', 'Document supprimé.');
    }

    /** Fichier d'un document : affiché dans le navigateur (PDF, image) ou téléchargé (Word, Excel). */
    public function file(DocumentSpace $space, AgentDocument $document): BinaryFileResponse
    {
        abort_unless($document->document_space_id === $space->id, 404);
        $disk = Storage::disk(AgentDocument::DISK);
        abort_unless($disk->exists($document->path), 404);
        $inline = in_array($document->extension(), ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true);
        $type = match ($document->extension()) {
            'pdf' => 'application/pdf', 'png' => 'image/png', 'webp' => 'image/webp', 'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return response()->download($disk->path($document->path), $document->downloadName(), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inline ? 'inline' : 'attachment');
    }

    /** Archive ZIP des documents (filtres de la page appliqués), un dossier par agent. */
    public function download(Request $request, DocumentSpace $space): BinaryFileResponse|RedirectResponse
    {
        $filters = $this->filters($request);
        $documents = $this->filtered($space, $filters)->with('collector:id,code,name')->orderBy('id')->get();
        if ($documents->isEmpty()) {
            return back()->with('warning', 'Aucun document à télécharger.');
        }

        $file = tempnam(sys_get_temp_dir(), 'docs');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $disk = Storage::disk(AgentDocument::DISK);
        foreach ($documents as $document) {
            if ($disk->exists($document->path)) {
                $zip->addFile($disk->path($document->path), $document->folderName().'/'.$document->downloadName());
            }
        }
        $zip->close();
        AuditLog::record('documents.telechargement', $space, ['documents' => $documents->count()] + array_filter($filters));
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $space->name) ?: 'documents'), '-');

        return response()->download($file, 'documents-'.strtolower($name).'-'.now()->format('Ymd-Hi').'.zip')->deleteFileAfterSend();
    }

    private function filters(Request $request): array
    {
        return [
            'type' => in_array($request->query('type'), array_keys(AgentDocument::TYPES), true) ? $request->query('type') : null,
            'status' => in_array($request->query('status'), array_keys(AgentDocument::STATUSES), true) ? $request->query('status') : null,
            'collector_id' => $request->integer('collector_id') ?: null,
            'health_zone_id' => $request->integer('health_zone_id') ?: null,
            'incomplets' => $request->boolean('incomplets'),
        ];
    }

    /** @return HasMany<AgentDocument> */
    private function filtered(DocumentSpace $space, array $filters): HasMany
    {
        return $space->documents()
            ->when($filters['type'], fn ($q, $type) => $q->where('type', $type))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['collector_id'], fn ($q, $id) => $q->where('collector_id', $id))
            ->when($filters['health_zone_id'], fn ($q, $zone) => $q->whereHas('collector', fn (Builder $c) => $c->where('health_zone_id', $zone)));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'nom de l\'espace', 'description' => 'consignes']);

        return $data + ['linked_to_survey' => $request->boolean('linked_to_survey')];
    }
}
