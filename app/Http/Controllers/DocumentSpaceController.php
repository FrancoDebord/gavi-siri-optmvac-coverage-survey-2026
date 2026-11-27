<?php

namespace App\Http\Controllers;

use App\Mail\AgentDocumentsMail;
use App\Models\AgentDocument;
use App\Models\AuditLog;
use App\Models\Collector;
use App\Models\DocumentSpace;
use App\Models\HealthZone;
use App\Models\PhotoProject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * Gestion des espaces de factures et rapports d'activité des agents (administrateur, investigateur principal) :
 * création, lien public et code PIN à partager, suivi par agent (qui a déposé quoi), validation ou demande de
 * correction, visionneuse, téléchargement (un par un ou en ZIP, un dossier par agent) et envoi par e-mail.
 */
class DocumentSpaceController extends Controller
{
    /** Envoi par e-mail : destinataires au plus, validité du lien, taille au-delà de laquelle rien n'est joint. */
    public const MAX_RECIPIENTS = 20;

    public const LINK_DAYS = 7;

    /** Microsoft Graph refuse les messages de plus de 4 Mo (pièces jointes encodées en base64, +33 %). */
    public const ATTACH_MAX_BYTES = 2_500_000;

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
            // Envoi par e-mail : taille de la sélection (pièces jointes ou lien seul), derniers envois.
            'selectionSize' => (int) $this->filtered($space, $filters)->sum('size'),
            'sends' => AuditLog::with('user:id,name')->where('action', 'documents.envoi_mail')
                ->where('subject_type', $space->getMorphClass())->where('subject_id', $space->id)->latest('id')->limit(5)->get(),
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
    public function review(Request $request, DocumentSpace $space, AgentDocument $document): RedirectResponse|JsonResponse
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

        // Visionneuse : statut mis à jour sans recharger la page.
        if ($request->expectsJson()) {
            return response()->json([
                'status' => $document->status,
                'label' => $document->statusLabel(),
                'note' => $document->review_note,
                'reviewer' => $request->user()->name,
            ]);
        }

        return back()->with('success', 'Document « '.$document->original_name.' » : '.mb_strtolower($document->statusLabel()).'.');
    }

    public function destroyDocument(DocumentSpace $space, AgentDocument $document): RedirectResponse
    {
        abort_unless($document->document_space_id === $space->id, 404);
        AuditLog::record('documents.document_supprime', $space, ['document' => $document->id, 'fichier' => $document->original_name, 'agent' => $document->authorLabel()]);
        $document->delete();

        return back()->with('success', 'Document supprimé.');
    }

    /**
     * Fichier d'un document : affiché dans le navigateur ou la visionneuse (PDF, image), téléchargé sinon (Word,
     * Excel) ou sur demande (?telecharger=1).
     */
    public function file(Request $request, DocumentSpace $space, AgentDocument $document): BinaryFileResponse
    {
        abort_unless($document->document_space_id === $space->id, 404);
        $disk = Storage::disk(AgentDocument::DISK);
        abort_unless($disk->exists($document->path), 404);
        $inline = $document->viewer() !== 'other' && ! $request->boolean('telecharger');
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

        AuditLog::record('documents.telechargement', $space, ['documents' => $documents->count()] + array_filter($filters));

        return $this->zipResponse($space, $documents);
    }

    /**
     * Envoi par e-mail des documents sélectionnés (filtres de la page) : récapitulatif par agent et lien de
     * téléchargement sécurisé (ZIP, valable LINK_DAYS jours, sans compte) ; fichiers joints si la sélection est légère.
     */
    public function email(Request $request, DocumentSpace $space): RedirectResponse
    {
        $data = $request->validate([
            'recipients' => ['required', 'string', 'max:2000'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:3000'],
        ], [], ['recipients' => 'destinataires', 'subject' => 'objet', 'message' => 'message']);

        // Adresses séparées par des virgules, points-virgules, espaces ou retours à la ligne.
        $recipients = collect(preg_split('/[\s,;]+/', $data['recipients'], -1, PREG_SPLIT_NO_EMPTY))->map(fn ($a) => mb_strtolower(trim($a)))->unique()->values();
        $invalid = $recipients->reject(fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL));
        if ($invalid->isNotEmpty() || $recipients->isEmpty() || $recipients->count() > self::MAX_RECIPIENTS) {
            return back()->withInput()->withErrors(['recipients' => $invalid->isNotEmpty()
                ? 'Adresse(s) invalide(s) : '.$invalid->implode(', ').'.'
                : 'Indiquez entre 1 et '.self::MAX_RECIPIENTS.' adresses e-mail.']);
        }

        $filters = $this->filters($request);
        $documents = $this->filtered($space, $filters)->with(['collector:id,code,name', 'team:id,code'])->orderBy('id')->get();
        if ($documents->isEmpty()) {
            return back()->withInput()->with('warning', 'Aucun document à envoyer pour cette sélection.');
        }

        $expires = now()->addDays(self::LINK_DAYS);
        $link = URL::temporarySignedRoute('document-spaces.shared', $expires,
            ['space' => $space->id, 'max_id' => $documents->max('id')] + array_filter(Arr::except($filters, ['incomplets'])));
        $attach = $documents->sum('size') <= self::ATTACH_MAX_BYTES;

        try {
            Mail::to($recipients->all())->send(new AgentDocumentsMail($space, $documents, $link, $expires, $data['subject'], $data['message'] ?? null, $attach, $request->user()));
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('warning', 'L\'e-mail n\'a pas pu être envoyé : '.$e->getMessage());
        }
        AuditLog::record('documents.envoi_mail', $space, [
            'destinataires' => $recipients->implode(', '), 'documents' => $documents->count(), 'pieces_jointes' => $attach,
        ] + array_filter(Arr::except($filters, ['incomplets'])));

        $message = $documents->count().' document(s) envoyé(s) à '.$recipients->implode(', ').($attach ? ' (en pièces jointes et par lien).' : ' (lien de téléchargement valable '.self::LINK_DAYS.' jours).');
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return back()->with('warning', 'E-mail préparé mais non envoyé : la messagerie est en mode « '.config('mail.default').' » (MAIL_MAILER=graph pour envoyer).');
        }

        return back()->with('success', $message);
    }

    /** Lien envoyé par e-mail (signé, à durée limitée, sans compte) : ZIP des documents envoyés, dans leur état actuel. */
    public function shared(Request $request, DocumentSpace $space): BinaryFileResponse
    {
        $documents = $this->filtered($space, $this->filters($request))->where('id', '<=', $request->integer('max_id'))
            ->with('collector:id,code,name')->orderBy('id')->get();
        abort_if($documents->isEmpty(), 404, 'Ces documents ne sont plus disponibles.');
        AuditLog::record('documents.telechargement_lien', $space, ['documents' => $documents->count()]);

        return $this->zipResponse($space, $documents);
    }

    /** Archive ZIP, un dossier par agent. */
    private function zipResponse(DocumentSpace $space, Collection $documents): BinaryFileResponse
    {
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
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $space->name) ?: 'documents'), '-');

        return response()->download($file, 'documents-'.strtolower($name).'-'.now()->format('Ymd-Hi').'.zip')->deleteFileAfterSend();
    }

    private function filters(Request $request): array
    {
        return [
            'type' => in_array($request->input('type'), array_keys(AgentDocument::TYPES), true) ? $request->input('type') : null,
            'status' => in_array($request->input('status'), array_keys(AgentDocument::STATUSES), true) ? $request->input('status') : null,
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
