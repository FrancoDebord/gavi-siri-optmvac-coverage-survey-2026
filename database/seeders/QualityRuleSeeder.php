<?php

namespace Database\Seeders;

use App\Models\QualityRule;
use App\Services\Quality\RuleCatalog;
use Illuminate\Database\Seeder;

/** Crée les règles du catalogue (sans écraser les réglages déjà modifiés dans l'interface). */
class QualityRuleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RuleCatalog::all() as $code => $rule) {
            QualityRule::firstOrCreate(['code' => $code], [
                'name' => $rule['name'],
                'description' => $rule['description'],
                'category' => $rule['category'],
                'severity' => $rule['severity'],
                'scope' => $rule['scope'],
                'variables' => $rule['variables'],
                'parameters' => $rule['parameters'] ?: null,
                'handler' => $rule['handler'],
                'suggestion' => $rule['suggestion'],
                'is_active' => true,
            ]);
        }

        // Exemple de règle personnalisée (expression XLSForm = condition d'erreur).
        QualityRule::firstOrCreate(['code' => 'EXPR_BEDNETS'], [
            'name' => 'Moustiquaires plus nombreuses que les dormeurs',
            'description' => 'Exemple de règle personnalisée : plus de moustiquaires que de personnes ayant dormi dans le ménage.',
            'category' => 'expression',
            'severity' => 'mineure',
            'scope' => 'household',
            'variables' => 'num_bednets,num_slept_last_night',
            'expression' => '${num_bednets} > ${num_slept_last_night}',
            'message' => 'Nombre de moustiquaires supérieur au nombre de personnes ayant dormi dans le ménage',
            'suggestion' => 'Recompter avec le binôme les moustiquaires ({valeur}) et les personnes ayant dormi dans le ménage ; corriger la valeur erronée.',
            'is_active' => true,
        ]);
    }
}
