<?php

use App\Services\Quality\RuleCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Règles qui comparent deux dates : la suggestion indique désormais que l'erreur peut porter sur l'une ou l'autre.
 * Texte de la règle mis à jour (sauf s'il a été personnalisé), puis suggestion recalculée sur toutes ses queries,
 * anciennes comprises, avec le même gabarit que le moteur.
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'VAC_BEFORE_DOB' => 'Relire sur le carnet la date « {question} » ({valeur}) et la date de naissance ; corriger l\'erreur de saisie (jour/mois inversés, année).',
        'VAC_DOSE_ORDER' => 'Relire sur le carnet les dates de la série ; corriger l\'inversion des doses ou du jour et du mois pour « {question} ».',
        'VAC_MIN_AGE' => 'Vérifier la date « {question} » et la date de naissance. Si elles sont exactes, justifier (dose administrée précocement).',
    ];

    public function up(): void
    {
        foreach (self::PREVIOUS as $code => $previous) {
            DB::table('quality_rules')->where('code', $code)->where(fn ($q) => $q->whereNull('suggestion')->orWhere('suggestion', $previous))
                ->update(['suggestion' => RuleCatalog::SUGGESTIONS[$code], 'updated_at' => now()]);
            $template = DB::table('quality_rules')->where('code', $code)->value('suggestion') ?? RuleCatalog::SUGGESTIONS[$code];

            DB::table('data_queries')->where('rule_code', $code)->select(['id', 'variable', 'variable_label', 'observed_value', 'expected'])
                ->orderBy('id')->chunkById(500, function ($queries) use ($template) {
                    foreach ($queries as $query) {
                        $suggestion = strtr($template, [
                            '{question}' => $query->variable_label ?? $query->variable ?? '—',
                            '{variable}' => $query->variable ?? '—',
                            '{valeur}' => $query->observed_value ?? 'vide',
                            '{attendu}' => $query->expected ?? '—',
                        ]);
                        DB::table('data_queries')->where('id', $query->id)->update(['suggestion' => mb_substr($suggestion, 0, 2000)]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Textes conservés : simple reformulation, sans effet sur les données.
    }
};
