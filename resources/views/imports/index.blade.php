<x-layouts.app title="Imports" page="imports">
    <x-page-header title="Imports de données" subtitle="Injection directe d'exports ODK Central (CSV) ou KoboToolbox (XLSX), liste des grappes et référentiel" />

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-database-up me-1"></i>Soumissions</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('imports.data') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label" for="main_file">Fichier principal (CSV ou XLSX)</label>
                            <input id="main_file" type="file" name="main_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="repeat_files">Fichiers des repeats (optionnel)</label>
                            <input id="repeat_files" type="file" name="repeat_files[]" class="form-control" accept=".csv,.xlsx,.xls" multiple>
                        </div>
                        <ul class="small text-muted ps-3">
                            <li><strong>ODK Central</strong> : export CSV dézippé ; fichier principal + <code>…-cg.csv</code>, <code>…-smc_cov_hh_cg_child_2023.csv</code>… liés par <code>KEY</code> / <code>PARENT_KEY</code>.</li>
                            <li><strong>KoboToolbox</strong> : export XLSX (une feuille par repeat, liées par <code>_index</code> / <code>_parent_index</code>), valeurs et noms XML.</li>
                            <li>Import idempotent sur l'UUID de soumission : réimporter un fichier met à jour sans créer de doublon.</li>
                        </ul>
                        <button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1"></i>Importer</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-bullseye me-1"></i>Liste des grappes et cibles</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('imports.clusters') }}" enctype="multipart/form-data" class="mb-2">
                        @csrf
                        <div class="input-group">
                            <input type="file" name="file" class="form-control" accept=".csv" required aria-label="Fichier CSV des grappes">
                            <button class="btn btn-primary">Importer</button>
                        </div>
                    </form>
                    <p class="small text-muted mb-1">Colonnes : <code>code, nom, village, cible_menages, cible_enfants, latitude, longitude</code> (séparateur , ou ;).</p>
                    <p class="small mb-0">{{ $clusters }} grappe(s) avec une cible. <a href="{{ route('imports.clusters.template') }}"><i class="bi bi-download me-1"></i>Télécharger le modèle pré-rempli</a></p>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><i class="bi bi-geo-alt me-1"></i>Référentiel géographique</div>
                <div class="card-body">
                    <p class="small text-muted">Recharge zones sanitaires, communes, arrondissements, villages, agents et binômes depuis les CSV du formulaire (<code>l1.csv</code>, <code>l2.csv</code>, <code>l3.csv</code>, <code>village.csv</code>, <code>l1_fieldworkers.csv</code>, <code>binomes.csv</code>).</p>
                    <form method="POST" action="{{ route('imports.references') }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Recharger le référentiel</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-bounding-box me-1"></i>Contours GPS des grappes et sous-grappes</span>
            <span class="d-flex flex-wrap gap-2">
                <a href="{{ route('imports.boundaries.archive') }}" class="btn btn-sm btn-success"><i class="bi bi-file-earmark-zip me-1"></i>Tout exporter (ZIP)</a>
                <a href="{{ route('imports.boundaries.compilation') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv me-1"></i>Points (CSV)</a>
                <a href="{{ route('imports.boundaries.geojson') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-map me-1"></i>Contours (GeoJSON)</a>
                <form method="POST" action="{{ route('imports.boundaries') }}" data-boundary-progress
                      data-progress-url="{{ route('imports.boundaries.progress', '00000000-0000-0000-0000-000000000000') }}">
                    @csrf
                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Réimporter le dossier</button>
                </form>
            </span>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('imports.boundaries.upload') }}" enctype="multipart/form-data" class="border rounded p-2 mb-3 bg-light" data-boundary-progress
                  data-progress-url="{{ route('imports.boundaries.progress', '00000000-0000-0000-0000-000000000000') }}"
                  data-confirm-title="Déposer les relevés" data-confirm-label="Déposer et importer"
                  data-confirm="Déposer les fichiers sélectionnés dans le dossier des relevés et mettre à jour les contours ? Un fichier portant le même nom qu'un relevé existant le remplace.">
                @csrf
                <label for="boundary-files" class="form-label small fw-semibold mb-1"><i class="bi bi-cloud-upload me-1"></i>Ajouter des relevés de délimitation (grappes ou sous-grappes)</label>
                <div class="input-group input-group-sm">
                    <input id="boundary-files" type="file" name="files[]" class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" accept=".csv" multiple required>
                    <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Déposer et importer</button>
                </div>
                @error('files')<div class="small text-danger">{{ $message }}</div>@enderror
                @error('files.*')<div class="small text-danger">{{ $message }}</div>@enderror
                <div class="form-text">Plusieurs fichiers à la fois. Les fichiers mal nommés sont refusés ; les autres sont importés aussitôt.</div>
            </form>
            {{-- Avancement de l'envoi puis du traitement (rempli par resources/js/pages/imports.js). --}}
            <div id="boundary-progress" class="border rounded p-2 mb-3 d-none" aria-live="polite">
                <div class="d-flex justify-content-between align-items-center small mb-1 gap-2">
                    <span class="fw-semibold" data-progress-label>Préparation…</span>
                    <span class="text-muted text-nowrap" data-progress-percent></span>
                </div>
                <div class="progress" role="progressbar" aria-label="Progression de l'import des contours" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                </div>
                <div class="small text-muted mt-1 text-truncate" data-progress-detail></div>
            </div>
            <p class="small text-muted mb-2">
                Fichiers du dossier <code>{{ $boundaryDirectory }}</code>, importés automatiquement toutes les 10 minutes :
                <code>ZONE-VIL-NN[-Sx][-Hx]-JJ-MM-AAAA.csv</code> (ex. <code>DAG-ASS-01-06-11-2026.csv</code>, <code>TCH-BEE-03-S2-07-11-2026.csv</code>,
                <code>TCH-KPR-06-H01-09-11-2026.csv</code> pour un hameau), points nommés <code>…-P1</code>, <code>…-P2</code>… et <code>…-H1-P1</code> pour les hameaux.
                Les points sont reliés dans l'ordre ; le contour d'une grappe ou sous-grappe réunit, hameau par hameau, le relevé le plus récent
                (un relevé sans hameau couvre toute la grappe). Le nom du fichier fait foi.
            </p>
            @if ($boundaries)
                <p class="small mb-2">
                    Dernier import : {{ $boundaries['generated_at'] }} · {{ $boundaries['clusters'] }} grappe(s), {{ $boundaries['sub_clusters'] }} sous-grappe(s), {{ $boundaries['points'] }} point(s).
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Fichier</th><th>Date du relevé</th><th>Village › grappe</th><th class="text-end">Points</th><th class="text-end">Hameaux</th><th class="text-end">Superficie</th><th>Statut</th><th>Remarques</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($boundaries['files'] as $file)
                            <tr @class(['table-danger' => $file['status'] === 'erreur', 'table-warning' => $file['status'] !== 'erreur' && $file['warnings'], 'text-muted' => $file['status'] === 'remplacé'])>
                                <td><code>{{ $file['file'] }}</code></td>
                                <td>{{ $file['date'] ? \Carbon\Carbon::parse($file['date'])->format('d/m/Y') : '—' }}</td>
                                <td>
                                    @if ($file['cluster_id'])
                                        <a href="{{ route('clusters.show', $file['cluster_id']) }}">{{ $file['village'] ?? $file['village_code'] }} › {{ $file['cluster_code'] }}</a>{{ $file['sub_cluster'] !== null ? ' · S'.$file['sub_cluster'] : '' }}{{ ($file['hamlet'] ?? null) !== null ? ' · H'.$file['hamlet'] : '' }}
                                    @else
                                        {{ $file['cluster_code'] ?? '—' }}{{ $file['sub_cluster'] !== null ? ' · S'.$file['sub_cluster'] : '' }}{{ ($file['hamlet'] ?? null) !== null ? ' · H'.$file['hamlet'] : '' }}
                                    @endif
                                </td>
                                <td class="text-end">{{ $file['points_count'] }}</td>
                                <td class="text-end">{{ $file['hamlets'] ?: '—' }}</td>
                                <td class="text-end text-nowrap">{{ $file['area_ha'] !== null ? number_format($file['area_ha'], 2, ',', ' ').' ha' : '—' }}</td>
                                <td><span class="badge {{ ['appliqué' => 'text-bg-success', 'remplacé' => 'text-bg-secondary', 'erreur' => 'text-bg-danger'][$file['status']] ?? 'text-bg-light' }}">{{ $file['status'] }}</span></td>
                                <td>
                                    @if ($file['error'])<div class="text-danger">{{ $file['error'] }}</div>@endif
                                    @foreach ($file['warnings'] as $warning)<div><i class="bi bi-exclamation-triangle text-warning me-1"></i>{{ $warning }}</div>@endforeach
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('imports.boundaries.destroy', $file['file']) }}" data-confirm-variant="danger" data-confirm-title="Supprimer le relevé"
                                          data-confirm-label="Supprimer" data-confirm="Supprimer le relevé {{ $file['file'] }} ? Le contour qu'il fournit sera retiré (ou remplacé par un relevé plus ancien de la même grappe).">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-link text-danger p-0" title="Supprimer ce relevé" aria-label="Supprimer {{ $file['file'] }}"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="small text-muted mb-0">Aucun import de contours pour le moment.</p>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header">Historique des imports</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th>Date</th><th>Type</th><th>Fichiers</th><th>Utilisateur</th><th>Statut</th><th style="min-width: 200px">Progression</th><th>Résultat</th></tr></thead>
                <tbody>
                @forelse ($batches as $batch)
                    @php([$label, $color] = $status[$batch->status] ?? [$batch->status, 'secondary'])
                    <tr @if (in_array($batch->status, ['pending', 'running'])) data-import-status="{{ route('imports.status', $batch) }}" @endif>
                        <td class="text-nowrap">{{ $batch->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ ['data' => 'Soumissions', 'clusters' => 'Grappes'][$batch->type] ?? $batch->type }} {{ $batch->format ? '('.strtoupper($batch->format).')' : '' }}</td>
                        <td>{{ collect($batch->files)->pluck('name')->implode(', ') }}</td>
                        <td>{{ $batch->user?->name }}</td>
                        <td><span data-status-label class="badge text-bg-{{ $color }}">{{ $label }}</span></td>
                        <td>
                            <div class="progress" role="progressbar" aria-label="Progression">
                                <div class="progress-bar {{ in_array($batch->status, ['pending', 'running']) ? 'progress-bar-striped progress-bar-animated' : '' }} {{ $batch->status === 'failed' ? 'bg-danger' : '' }}" style="width: {{ $batch->progress() }}%">{{ $batch->progress() }} %</div>
                            </div>
                        </td>
                        <td data-message>{{ $batch->message }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-3">Aucun import.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($batches->hasPages())<div class="card-footer">{{ $batches->links() }}</div>@endif
    </div>
</x-layouts.app>
