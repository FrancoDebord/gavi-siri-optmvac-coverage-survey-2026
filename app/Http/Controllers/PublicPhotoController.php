<?php

namespace App\Http\Controllers;

use App\Models\FieldPhoto;
use App\Models\PhotoProject;
use App\Models\Team;
use App\Models\Village;
use App\Services\Photos\FieldPhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espace photos public d'un projet (sans compte) : lien secret, puis code PIN une fois par appareil (session).
 * Les binômes déposent leurs photos et voient la galerie du projet ; ni suppression ni téléchargement en lot.
 */
class PublicPhotoController extends Controller
{
    /** Taille maximale d'une photo (Ko), plafonnée par la configuration PHP du serveur. */
    public static function maxKilobytes(): int
    {
        $bytes = fn (string $value) => (int) $value * match (strtolower(substr(trim($value), -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        };
        $server = min(array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]) ?: [PHP_INT_MAX]);

        return (int) min(15 * 1024, floor($server / 1024));
    }

    public function show(Request $request, string $token): View
    {
        $project = $this->project($token);
        if (! $this->unlocked($request, $project)) {
            return view('photos.public.pin', ['project' => $project]);
        }

        return view('photos.public.space', [
            'project' => $project,
            'photos' => $project->photos()->with(['team:id,code,name', 'village:id,name'])->latest('id')->paginate(48),
            'teams' => $project->linked_to_survey ? Team::with('healthZone:id,name')->orderBy('code')->get(['id', 'code', 'name', 'health_zone_id']) : collect(),
            'villages' => $project->linked_to_survey ? Village::with('healthZone:id,name')->whereHas('households')->orderBy('name')->get(['id', 'name', 'health_zone_id']) : collect(),
            'maxKb' => self::maxKilobytes(),
        ]);
    }

    public function unlock(Request $request, string $token): RedirectResponse
    {
        $project = $this->project($token);
        $key = 'photo-pin:'.$project->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['pin' => 'Trop d\'essais. Réessayez dans '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }
        $request->validate(['pin' => ['required', 'string', 'max:12']], [], ['pin' => 'code PIN']);
        if (! hash_equals((string) $project->pin, preg_replace('/\D/', '', (string) $request->input('pin')))) {
            RateLimiter::hit($key, 600);

            return back()->withErrors(['pin' => 'Code PIN incorrect.']);
        }
        RateLimiter::clear($key);
        $request->session()->put($project->sessionKey(), true);

        return redirect()->route('photos.public.show', $project->token);
    }

    /** Dépôt d'une ou plusieurs photos (le navigateur les envoie une par une ; sans JS, toutes ensemble). */
    public function upload(Request $request, string $token, FieldPhotoStore $store): JsonResponse|RedirectResponse
    {
        $project = $this->project($token);
        abort_unless($this->unlocked($request, $project), 403, 'Saisissez d\'abord le code PIN de l\'espace.');

        $linked = $project->linked_to_survey;
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:20'],
            'photos.*' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(FieldPhotoStore::MIMES)), 'max:'.self::maxKilobytes()],
            'team_id' => [Rule::requiredIf($linked), 'nullable', 'integer', Rule::exists('teams', 'id')],
            'village_id' => ['nullable', 'integer', Rule::exists('villages', 'id')],
            'author_name' => [Rule::requiredIf(! $linked), 'nullable', 'string', 'max:120'],
            'place' => ['nullable', 'string', 'max:160'],
            'caption' => ['nullable', 'string', 'max:500'],
            'taken_at' => ['nullable', 'array'],
            'taken_at.*' => ['nullable', 'numeric'],
        ], [
            'photos.*.mimetypes' => 'Seules les photos JPEG, PNG ou WebP sont acceptées.',
            'photos.*.max' => 'Photo trop lourde (maximum '.round(self::maxKilobytes() / 1024, 1).' Mo).',
        ], ['team_id' => 'binôme', 'author_name' => 'nom', 'village_id' => 'village', 'place' => 'lieu', 'caption' => 'légende', 'photos' => 'photos']);

        $meta = $linked
            ? ['team_id' => $data['team_id'] ?? null, 'village_id' => $data['village_id'] ?? null, 'caption' => $data['caption'] ?? null]
            : ['author_name' => $data['author_name'] ?? null, 'place' => $data['place'] ?? null, 'caption' => $data['caption'] ?? null];
        $saved = [];
        foreach ($data['photos'] as $i => $file) {
            $saved[] = $store->store($project, $file, $meta, $request->ip(), $data['taken_at'][$i] ?? null);
        }

        if ($request->expectsJson()) {
            return response()->json(['saved' => count($saved), 'ids' => array_map(fn ($p) => $p->id, $saved)]);
        }

        return redirect()->route('photos.public.show', $project->token)->with('success', count($saved).' photo(s) déposée(s). Merci !');
    }

    /** Fichier d'une photo (vignette ou original) : PIN saisi sur cet appareil, ou gestionnaire connecté. */
    public function file(Request $request, string $token, FieldPhoto $photo, string $variant): Response
    {
        $project = PhotoProject::where('token', $token)->firstOrFail();
        abort_unless($photo->photo_project_id === $project->id, 404);
        $manager = $request->user()?->can('manage field photos');
        abort_unless($manager || ($project->active && $this->unlocked($request, $project)), 403);

        $path = $variant === 'vignette' && $photo->thumb_path ? $photo->thumb_path : $photo->path;
        abort_unless(Storage::disk(FieldPhoto::DISK)->exists($path), 404);

        return response()->file(Storage::disk(FieldPhoto::DISK)->path($path), [
            'Content-Type' => $path === $photo->thumb_path ? 'image/jpeg' : $photo->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Espace actif seulement (lien désactivé : page introuvable). */
    private function project(string $token): PhotoProject
    {
        return PhotoProject::where('token', $token)->where('active', true)->firstOrFail();
    }

    private function unlocked(Request $request, PhotoProject $project): bool
    {
        return (bool) $request->session()->get($project->sessionKey());
    }
}
