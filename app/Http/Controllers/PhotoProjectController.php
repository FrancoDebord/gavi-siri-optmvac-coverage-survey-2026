<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FieldPhoto;
use App\Models\PhotoProject;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Gestion des espaces photos de terrain (administrateur, investigateur principal) : création par projet, lien
 * public et code PIN à partager, ouverture / fermeture, galerie, suppression et téléchargement des photos (ZIP).
 */
class PhotoProjectController extends Controller
{
    public function index(): View
    {
        return view('photos.index', [
            'projects' => PhotoProject::withCount('photos')->withMax('photos', 'created_at')->with('creator:id,name')->latest('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $project = PhotoProject::create($data + ['created_by' => $request->user()->id]);
        AuditLog::record('photos.espace_cree', $project, ['nom' => $project->name]);

        return redirect()->route('photo-projects.show', $project)->with('success', 'Espace photos créé : partagez le lien et le code PIN aux binômes.');
    }

    public function show(Request $request, PhotoProject $project): View
    {
        $teamId = $request->integer('team_id') ?: null;
        $photos = $project->photos()->with(['team:id,code,name', 'village:id,name'])
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))->latest('id')->paginate(48)->withQueryString();

        return view('photos.show', [
            'project' => $project,
            'photos' => $photos,
            'teamId' => $teamId,
            'teams' => $project->linked_to_survey
                ? Team::whereIn('id', $project->photos()->whereNotNull('team_id')->select('team_id'))->orderBy('code')->get(['id', 'code', 'name'])
                : collect(),
            'total' => $project->photos()->count(),
            'totalSize' => (int) $project->photos()->sum('size'),
        ]);
    }

    public function update(Request $request, PhotoProject $project): RedirectResponse
    {
        $project->update($this->validated($request) + ['active' => $request->boolean('active')]);
        AuditLog::record('photos.espace_modifie', $project, ['actif' => $project->active]);

        return back()->with('success', 'Espace photos mis à jour.');
    }

    /** Nouveau lien ou nouveau PIN : l'ancien ne fonctionne plus (appareils à déverrouiller de nouveau). */
    public function regenerate(Request $request, PhotoProject $project): RedirectResponse
    {
        $what = $request->validate(['what' => ['required', 'in:lien,pin']])['what'];
        $what === 'lien' ? $project->forceFill(['token' => PhotoProject::newToken()])->save() : $project->forceFill(['pin' => PhotoProject::newPin()])->save();
        AuditLog::record('photos.'.($what === 'lien' ? 'lien' : 'pin').'_renouvele', $project);

        return back()->with('success', $what === 'lien' ? 'Nouveau lien créé : l\'ancien ne fonctionne plus.' : 'Nouveau code PIN créé : l\'ancien ne fonctionne plus.');
    }

    public function destroy(PhotoProject $project): RedirectResponse
    {
        AuditLog::record('photos.espace_supprime', null, ['nom' => $project->name, 'photos' => $project->photos()->count()]);
        $project->delete();
        Storage::disk(FieldPhoto::DISK)->deleteDirectory('field-photos/'.$project->id);

        return redirect()->route('photo-projects.index')->with('success', 'Espace photos supprimé avec ses photos.');
    }

    public function destroyPhoto(PhotoProject $project, FieldPhoto $photo): RedirectResponse
    {
        abort_unless($photo->photo_project_id === $project->id, 404);
        AuditLog::record('photos.photo_supprimee', $project, ['photo' => $photo->id, 'fichier' => $photo->original_name]);
        $photo->delete();

        return back()->with('success', 'Photo supprimée.');
    }

    /** Archive ZIP des photos originales (toutes, ou celles du binôme filtré). */
    public function download(Request $request, PhotoProject $project): BinaryFileResponse|RedirectResponse
    {
        $teamId = $request->integer('team_id') ?: null;
        $photos = $project->photos()->with(['team:id,code,name', 'village:id,name'])->when($teamId, fn ($q) => $q->where('team_id', $teamId))->orderBy('id')->get();
        if ($photos->isEmpty()) {
            return back()->with('warning', 'Aucune photo à télécharger.');
        }

        $file = tempnam(sys_get_temp_dir(), 'photos');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $disk = Storage::disk(FieldPhoto::DISK);
        foreach ($photos as $photo) {
            if ($disk->exists($photo->path)) {
                $zip->addFile($disk->path($photo->path), $photo->downloadName());
                $zip->setCompressionName($photo->downloadName(), ZipArchive::CM_STORE); // déjà compressées
            }
        }
        $zip->close();
        AuditLog::record('photos.telechargement', $project, ['photos' => $photos->count(), 'binome' => $teamId]);
        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $project->name) ?: 'photos'), '-');

        return response()->download($file, 'photos-'.strtolower($name).'-'.now()->format('Ymd-Hi').'.zip')->deleteFileAfterSend();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'nom du projet', 'description' => 'description']);

        return $data + ['linked_to_survey' => $request->boolean('linked_to_survey')];
    }
}
