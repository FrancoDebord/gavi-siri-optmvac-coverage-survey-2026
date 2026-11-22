<?php

use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-32, MAJ-34 et MAJ-37 passent d'une expression à une règle dédiée, qui détaille la query : dose et vaccin en cause
 * (MAJ-32), carte / rappel / âge lus et carnet « Enfant complètement vacciné » sans query (MAJ-34), toutes les dates de
 * cycles CPS et cycles en cause (MAJ-37). Texte et suggestion mis à jour s'ils n'ont pas été personnalisés.
 * Les queries existantes gardent leur identité (même variable) : le prochain contrôle qualité met leur message à jour,
 * et ferme celles qui ne sont plus des anomalies (carnet « complètement vacciné »).
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'MAJ_32' => [
            'expression' => '(${measmth9} = 1 and ${age_months_est} < 9) or (${measmth15} = 1 and ${age_months_est} < 15) or ((${penta3_rec} = 1 or ${opv3_rec} = 1 or ${pcv3_rec} = 1) and ${age_months_est} < 3) or (${rtss1_rec} = 1 and ${age_months_est} < 5) or (${rtss4_rec} = 1 and ((${l1} = 2 and ${age_months_est} < 15) or (${l1} = 1 and ${age_months_est} < 18)))',
            'message' => 'Enfant trop jeune pour cette dose.', 'suggestion' => 'Vérifier l\'âge de l\'enfant ou la réponse.',
        ],
        'MAJ_34' => [
            'expression' => '${three_MV_doses} = 1 and (${mal3} = 0 or ${rtss3_rec} = 0 or ${age_months_est} < 7)',
            'message' => '« 3 doses de VAP » contredit la carte, le rappel ou l\'âge.', 'suggestion' => 'Corriger la réponse de revue.',
        ],
        'MAJ_37' => [
            'expression' => '${date_2} <= ${date_1} or ${date_3} <= ${date_2} or ${date_4} <= ${date_3} or ${date_5} <= ${date_4}',
            'message' => 'Dates des cycles CPS dans le désordre.', 'suggestion' => 'Relire la carte CPS et corriger.',
        ],
    ];

    public function up(): void
    {
        $rules = BeninRuleSet::rules();
        foreach (self::PREVIOUS as $code => $previous) {
            $rule = DB::table('quality_rules')->where('code', $code)->first();
            if (! $rule) {
                continue;
            }
            $new = $rules[$code];
            DB::table('quality_rules')->where('id', $rule->id)->update([
                'category' => 'metier',
                'handler' => $new['handler'],
                'expression' => null,
                'variables' => $new['variables'],
                'message' => in_array($rule->message, [null, $previous['message']], true) ? $new['message'] : $rule->message,
                'suggestion' => in_array($rule->suggestion, [null, $previous['suggestion']], true) ? $new['suggestion'] : $rule->suggestion,
                'description' => $new['message'].' (règle '.str_replace('_', '-', $code).' du document qualité Bénin)',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::PREVIOUS as $code => $previous) {
            DB::table('quality_rules')->where('code', $code)->update([
                'category' => 'expression', 'handler' => null, 'expression' => $previous['expression'],
                'message' => $previous['message'], 'suggestion' => $previous['suggestion'], 'updated_at' => now(),
            ]);
        }
    }
};
