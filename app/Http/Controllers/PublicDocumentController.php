<?php

namespace App\Http\Controllers;

use App\Models\AgentDocument;
use App\Models\Collector;
use App\Models\DocumentSpace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Espace public de dépôt des factures et rapports d'activité (sans compte) : lien secret, puis code PIN une fois par
 * appareil (session). L'agent choisit son nom, le type de document et dépose ses fichiers. Il ne revoit que les
 * documents déposés depuis cet appareil (noms et statut, sans téléchargement) : les documents des autres agents ne
 * sont visibles que des gestionnaires.
 */
class PublicDocumentController extends Controller
{
    public const MAX_FILES = 5;

    public function show(Request $request, string $token): View
    {
        $space = $this->space($token);
        if (! $this->unlocked($request, $space)) {
            return view('documents.public.pin', ['space' => $space]);
        }

        // Compte binôme connecté : seulement les agents de son binôme.
        $teamId = $request->user()?->team_id;
        $collectors = $space->linked_to_survey
            ? Collector::with(['healthZone:id,name', 'team:id,code'])->where('is_active', true)
                ->when($teamId, fn ($q) => $q->where('team_id', $teamId))->orderBy('name')->get(['id', 'code', 'name', 'team_id', 'health_zone_id'])
            : collect();

        return view('documents.public.space', [
            'space' => $space,
            'collectors' => $collectors,
            'mine' => AgentDocument::with('collector:id,code,name')->where('document_space_id', $space->id)
                ->whereIn('id', (array) $request->session()->get($space->uploadsKey(), []))->latest('id')->get(),
            'maxKb' => PublicPhotoController::maxKilobytes(),
            'extensions' => array_keys(AgentDocument::FORMATS),
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $space = $this->space($token);
        $key = 'document-pin:'.$space->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['pin' => 'Trop d\'essais. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }
        $request->validate(['pin' => ['required', 'string', 'max:12']], [], ['pin' => 'code PIN']);
        if (! hash_equals((string) $space->pin, preg_replace('/\D/', '', (string) $request->input('pin')))) {
            RateLimiter::hit($key, 600);

            return back()->withErrors(['pin' => 'Code PIN incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->put($space->sessionKey(), true);

        return redirect()->route('documents.public.show', $space->token);
    }

    /** Dépôt d'un ou plusieurs fichiers (facture ou rapport d'activité) pour un agent. */
    public function upload(Request $request, string $token): RedirectResponse
    {
        $space = $this->space($token);
        abort_unless($this->unlocked($request, $space), 403, 'Saisissez d\'abord le code PIN de l\'espace.');

        $linked = $space->linked_to_survey;
        $teamId = $request->user()?->team_id;
        $mimes = array_values(array_unique(array_merge(...array_values(AgentDocument::FORMATS))));
        $data = $request->validate([
            // Enquête : agent de la liste, ou nom libre pour une personne hors liste (superviseur…).
            'collector_id' => [
                Rule::requiredIf($linked && ! $request->filled('author_name')), 'nullable', 'integer',
                Rule::exists('collectors', 'id')->where('is_active', true)->when($teamId, fn ($rule) => $rule->where('team_id', $teamId)),
            ],
            'author_name' => [Rule::requiredIf(! $linked || ! $request->filled('collector_id')), 'nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(AgentDocument::TYPES))],
            'comment' => ['nullable', 'string', 'max:500'],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => [
                'required', 'file', 'max:'.PublicPhotoController::maxKilobytes(),
                'extensions:'.implode(',', array_keys(AgentDocument::FORMATS)), 'mimetypes:'.implode(',', $mimes),
            ],
        ], [
            'collector_id.required' => 'Choisissez votre nom dans la liste (ou saisissez-le plus bas si vous n\'y êtes pas).',
            'files.*.extensions' => 'Format non accepté : PDF, photo (JPEG, PNG, WebP), Word ou Excel.',
            'files.*.mimetypes' => 'Format non accepté : PDF, photo (JPEG, PNG, WebP), Word ou Excel.',
            'files.*.max' => 'Fichier trop lourd (maximum '.round(PublicPhotoController::maxKilobytes() / 1024, 1).' Mo).',
            'files.max' => 'Au plus '.self::MAX_FILES.' fichiers par envoi.',
        ], ['collector_id' => 'agent', 'author_name' => 'nom', 'type' => 'type de document', 'comment' => 'commentaire', 'files' => 'fichiers']);

        $collector = $linked && ! empty($data['collector_id']) ? Collector::find($data['collector_id']) : null;
        $ids = [];
        foreach ($data['files'] as $file) {
            $ids[] = $this->store($space, $file, [
                'collector_id' => $collector?->id,
                'team_id' => $collector?->team_id,
                'author_name' => $collector ? null : ($data['author_name'] ?? null),
                'type' => $data['type'],
                'comment' => $data['comment'] ?? null,
                'uploader_ip' => $request->ip(),
            ])->id;
        }
        $request->session()->put($space->uploadsKey(), array_merge((array) $request->session()->get($space->uploadsKey(), []), $ids));

        $label = AgentDocument::TYPES[$data['type']];

        return redirect()->route('documents.public.show', $space->token)
            ->with('success', count($ids).' fichier(s) déposé(s) : '.Str::lower($label).'. Merci !');
    }

    private function store(DocumentSpace $space, UploadedFile $file, array $meta): AgentDocument
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $path = $file->storeAs('agent-documents/'.$space->id.'/'.now()->format('Y-m'), Str::uuid().'.'.$ext, AgentDocument::DISK);

        return AgentDocument::create($meta + [
            'document_space_id' => $space->id,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => mb_substr((string) $file->getMimeType(), 0, 120),
            'size' => (int) $file->getSize(),
            'status' => AgentDocument::STATUS_RECEIVED,
        ]);
    }

    /** Espace actif seulement (lien désactivé : page introuvable). */
    private function space(string $token): DocumentSpace
    {
        return DocumentSpace::where('token', $token)->where('active', true)->firstOrFail();
    }

    private function unlocked(Request $request, DocumentSpace $space): bool
    {
        return (bool) $request->session()->get($space->sessionKey());
    }

    /** Comptes qui ouvrent l'espace sans code PIN depuis l'application (mêmes droits que les espaces photos). */
    public static function canOpenFromAccount(User $user): bool
    {
        return $user->hasAnyRole([User::ROLE_TEAM, User::ROLE_SUPERVISOR]) || $user->hasOwnTeam() || $user->can('manage agent documents');
    }
}
