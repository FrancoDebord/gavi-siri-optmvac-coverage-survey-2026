<?php

namespace App\Services\Export;

use App\Models\DataCorrection;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\QueryEvent;
use App\Services\Cleaning\CorrectionApplier;
use App\Services\Cleaning\SourceSnapshot;
use App\Services\Dictionary\Dictionary;
use App\Services\Import\NormalizedSubmission;

/**
 * Écrit les jeux de données en CSV (UTF-8 avec BOM, séparateur virgule, une ligne d'en-tête) :
 * - base brute : chaque soumission telle que reçue d'ODK (y compris les soumissions exclues) ;
 * - base nettoyée : source + corrections validées, soumissions exclues écartées ;
 * une table par niveau (ménages, puis une table par repeat), le membre étant identifié par son chemin
 * (ex. cg[1]/smc_cov_hh_cg_child_2023[2], même notation que les queries et le registre des corrections) ;
 * - registre des corrections, queries et leur historique, observations de supervision, dictionnaire.
 */
class DatasetExporter
{
    private Dictionary $dictionary;

    public function __construct(?Dictionary $dictionary = null)
    {
        $this->dictionary = $dictionary ?? Dictionary::current();
    }

    /**
     * @return array<string, array{rows: int, description: string}> fichier (relatif à $dir) => lignes et description
     */
    public function writeBase(string $dir, string $kind): array
    {
        $clean = $kind === 'nettoyee';
        $applier = new CorrectionApplier;
        $households = fn () => ($clean ? Household::query() : Household::withoutGlobalScope('included'))
            ->with(['healthZone:id,name', 'village:id,name', 'cluster:id,code', 'collector:id,code,team_id', 'collector.team:id,code'])->orderBy('id');
        $submission = fn (Household $h) => $clean ? $applier->apply(SourceSnapshot::of($h))[0] : SourceSnapshot::of($h);

        // 1er passage : colonnes de chaque table (ordre du formulaire, puis variables hors dictionnaire).
        $columns = [];
        $households()->chunk(200, function ($chunk) use (&$columns, $submission) {
            foreach ($chunk as $household) {
                foreach ($this->flatten($submission($household)) as $table => $rows) {
                    foreach ($rows as $row) {
                        $columns[$table] = ($columns[$table] ?? []) + array_fill_keys(array_keys($row['data']), true);
                    }
                }
            }
        });
        // Même structure pour les deux bases : toutes les variables du formulaire de chaque niveau, puis celles trouvées.
        $columns += ['menages' => []];
        foreach ($this->dictionary->variables() as $name => $variable) {
            if (in_array($variable['base_type'], ['note', 'begin group', 'end group', 'begin repeat', 'end repeat'], true)) {
                continue;
            }
            $table = $variable['repeat_name'] ?: 'menages';
            $columns[$table] = ($columns[$table] ?? []) + [$name => true];
        }
        foreach ($columns as $table => $keys) {
            $columns[$table] = $this->ordered(array_keys($keys));
        }

        // 2e passage : écriture.
        $handles = [];
        $counts = [];
        $meta = fn (string $table) => $table === 'menages'
            ? ($clean
                ? ['submission_uuid', 'id_menage', 'zone_sanitaire', 'village', 'grappe', 'sous_grappe', 'agent', 'binome', 'date_interview', 'date_envoi', 'version_formulaire', 'nb_corrections']
                : ['submission_uuid', 'date_envoi', 'version_formulaire', 'statut_revue_odk', 'exclue_base_nettoyee'])
            : ['submission_uuid', 'membre'];
        foreach ($columns as $table => $keys) {
            $handles[$table] = $this->open("{$dir}/{$table}.csv", [...$meta($table), ...$keys]);
            $counts[$table] = 0;
        }
        $corrections = $clean ? DataCorrection::where('status', DataCorrection::STATUS_VALIDATED)->selectRaw('submission_uuid, count(*) as n')->groupBy('submission_uuid')->pluck('n', 'submission_uuid') : collect();

        $households()->chunk(200, function ($chunk) use (&$counts, $handles, $columns, $submission, $clean, $corrections) {
            foreach ($chunk as $household) {
                $s = $submission($household);
                foreach ($this->flatten($s) as $table => $rows) {
                    foreach ($rows as $row) {
                        $head = $table === 'menages'
                            ? ($clean
                                ? [$s->uuid, $household->household_code, $household->healthZone?->name, $household->village?->name, $household->cluster?->code, $household->sub_cluster,
                                    $household->collector?->code, $household->collector?->team?->code, $household->interview_date?->toDateString(), $s->submittedAt, $s->formVersion, (int) ($corrections[$s->uuid] ?? 0)]
                                : [$s->uuid, $s->submittedAt, $s->formVersion, $s->reviewState, $household->excluded_at ? 'oui' : 'non'])
                            : [$s->uuid, $row['path']];
                        $this->put($handles[$table], [...$head, ...array_map(fn ($key) => self::cell($row['data'][$key] ?? null), $columns[$table])]);
                        $counts[$table]++;
                    }
                }
            }
        });
        foreach ($handles as $handle) {
            fclose($handle);
        }

        $label = $clean ? 'base nettoyée' : 'base brute (telle que reçue d\'ODK)';

        return collect($counts)->mapWithKeys(fn ($n, $table) => ["{$table}.csv" => [
            'rows' => $n,
            'description' => $table === 'menages' ? "Ménages – {$label} : une ligne par soumission" : "Repeat « {$table} » – {$label} : une ligne par membre",
        ]])->all();
    }

    /**
     * Tables d'une soumission : « menages » (variables hors repeats) et une table par repeat.
     *
     * @return array<string, list<array{path: ?string, data: array}>>
     */
    public function flatten(NormalizedSubmission $s): array
    {
        $data = $s->data;
        unset($data[DataCorrection::EXCLUSION], $data['__repeats']);
        $tables = ['menages' => [['path' => null, 'data' => $data]]];
        $walk = function (array $repeats, string $parent) use (&$walk, &$tables) {
            foreach ($repeats as $name => $instances) {
                foreach (array_values($instances) as $i => $instance) {
                    $path = ltrim($parent.'/'.$name.'['.($i + 1).']', '/');
                    $tables[$name][] = ['path' => $path, 'data' => $instance['data'] ?? []];
                    $walk($instance['repeats'] ?? [], $path);
                }
            }
        };
        $walk($s->repeats, '');

        return $tables;
    }

    /** @return array<string, array{rows: int, description: string}> */
    public function writeRegistry(string $dir): array
    {
        $handle = $this->open("{$dir}/registre_corrections.csv", ['correction', 'submission_uuid', 'id_menage', 'query', 'variable', 'membre', 'valeur_odk', 'valeur_avant', 'valeur_apres',
            'motif', 'reference_source', 'commentaire', 'statut', 'propose_par', 'propose_le', 'decide_par', 'decide_le', 'signature', 'commentaire_decision', 'remplacee_par', 'appliquee_le']);
        $n = 0;
        DataCorrection::with(['household:id,household_code', 'proposer:id,name', 'decider:id,name'])->orderBy('id')->chunk(500, function ($rows) use ($handle, &$n) {
            foreach ($rows as $c) {
                $this->put($handle, ['C-'.str_pad((string) $c->id, 6, '0', STR_PAD_LEFT), $c->submission_uuid, $c->household?->household_code, $c->data_query_id ? DataQuery::codeFor($c->data_query_id) : null,
                    $c->variable, $c->instance_path, $c->source_value, $c->old_value, $c->new_value, $c->reasonLabel(), $c->source_reference, $c->comment, $c->statusLabel(),
                    $c->proposer?->name, $c->proposed_at?->toIso8601String(), $c->decider?->name, $c->decided_at?->toIso8601String(), $c->signed ? 'oui' : 'non', $c->decision_comment,
                    $c->superseded_by ? 'C-'.str_pad((string) $c->superseded_by, 6, '0', STR_PAD_LEFT) : null, $c->applied_at?->toIso8601String()]);
                $n++;
            }
        });
        fclose($handle);

        return ['registre_corrections.csv' => ['rows' => $n, 'description' => 'Registre des corrections (piste d\'audit) : valeur ODK, valeur remplacée, nouvelle valeur, motif, auteur, décision, signature']];
    }

    /** @return array<string, array{rows: int, description: string}> */
    public function writeQueries(string $dir): array
    {
        $files = [];
        foreach ([QualityRule::KIND_QUERY => 'queries', QualityRule::KIND_OBSERVATION => 'observations_supervision'] as $kind => $name) {
            $handle = $this->open("{$dir}/{$name}.csv", ['code', 'regle', 'libelle', 'severite', 'statut', 'resolution', 'submission_uuid', 'id_menage', 'variable', 'membre',
                'valeur_observee', 'attendu', 'message', 'assignee_a', 'echeance', 'reponse_par', 'reponse_le', 'validee_par', 'detectee_le', 'resolue_le', 'fermee_le', 'fermeture_automatique']);
            $n = 0;
            DataQuery::withoutGlobalScope('kind')->where('kind', $kind)->with(['assignee:id,name', 'responder:id,name', 'validator:id,name'])->orderBy('id')
                ->chunk(500, function ($rows) use ($handle, &$n) {
                    foreach ($rows as $q) {
                        $this->put($handle, [DataQuery::codeFor($q->id), $q->rule_code, $q->label, $q->severity, DataQuery::STATUSES[$q->status] ?? $q->status, DataQuery::RESOLUTIONS[DataQuery::resolutionOf($q->status)]['label'],
                            $q->submission_uuid, $q->household_code, $q->variable, $q->instance_path, $q->observed_value, $q->expected, $q->message, $q->assignee?->name, $q->due_on?->toDateString(),
                            $q->responder?->name, $q->responded_at?->toIso8601String(), $q->validator?->name, $q->detected_at?->toIso8601String(), $q->resolved_at?->toIso8601String(),
                            $q->closed_at?->toIso8601String(), $q->auto_closed ? 'oui' : 'non']);
                        $n++;
                    }
                });
            fclose($handle);
            $files["{$name}.csv"] = ['rows' => $n, 'description' => $kind === QualityRule::KIND_QUERY ? 'Queries de données et leur état' : 'Observations de supervision (paradonnées)'];
        }

        $handle = $this->open("{$dir}/historique_queries.csv", ['query', 'date', 'utilisateur', 'type', 'statut_avant', 'statut_apres', 'commentaire', 'signature']);
        $n = 0;
        QueryEvent::with('user:id,name')->orderBy('id')->chunk(1000, function ($rows) use ($handle, &$n) {
            foreach ($rows as $e) {
                $this->put($handle, [DataQuery::codeFor($e->data_query_id), $e->created_at?->toIso8601String(), $e->user?->name ?? 'Système', $e->type,
                    $e->from_status ? (DataQuery::STATUSES[$e->from_status] ?? $e->from_status) : null, $e->to_status ? (DataQuery::STATUSES[$e->to_status] ?? $e->to_status) : null, $e->comment, $e->signed ? 'oui' : 'non']);
                $n++;
            }
        });
        fclose($handle);
        $files['historique_queries.csv'] = ['rows' => $n, 'description' => 'Historique horodaté de chaque query : auteur, changement de statut, motif, signature'];

        return $files;
    }

    /** @return array<string, array{rows: int, description: string}> */
    public function writeDictionary(string $dir): array
    {
        $handle = $this->open("{$dir}/dictionnaire.csv", ['variable', 'libelle', 'type', 'liste_choix', 'repeat', 'obligatoire', 'condition_affichage', 'contrainte']);
        $n = 0;
        foreach ($this->dictionary->variables() as $name => $v) {
            $this->put($handle, [$name, trim(strip_tags((string) ($v['label'] ?? ''))), $v['type'] ?? null, $v['list_name'] ?? null, implode(' › ', $this->dictionary->repeatPath($name)),
                ! empty($v['required']) ? 'oui' : 'non', $v['relevant'] ?? null, $v['constraint'] ?? null]);
            $n++;
        }
        fclose($handle);

        return ['dictionnaire.csv' => ['rows' => $n, 'description' => 'Dictionnaire des variables du formulaire (version '.($this->dictionary->formVersion?->version ?? '—').')']];
    }

    /** Variables dans l'ordre du formulaire, puis les autres par ordre alphabétique. */
    private function ordered(array $keys): array
    {
        $position = fn ($key) => $this->dictionary->variable($key)['position'] ?? PHP_INT_MAX;
        usort($keys, fn ($a, $b) => [$position($a), $a] <=> [$position($b), $b]);

        return $keys;
    }

    /** @return resource */
    private function open(string $path, array $header)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        $this->put($handle, $header);

        return $handle;
    }

    private function put($handle, array $row): void
    {
        fputcsv($handle, array_map(fn ($v) => $v === null ? '' : (string) $v, $row), ',', '"', '');
    }

    private static function cell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
