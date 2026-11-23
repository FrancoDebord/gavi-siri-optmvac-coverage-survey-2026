<?php

use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-11 passe d'une expression à une règle dédiée : la query montre la réponse « caregiver », la tranche d'âge du
 * membre et l'âge du caregiver interrogé, corrigeables chacune. Texte et suggestion mis à jour s'ils n'ont pas été
 * personnalisés. Les queries gardent leur identité (même membre, même variable) : le prochain contrôle met leur message à jour.
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'expression' => '${person_caregiver} = 1 and ${age_group} <= 6',
        'message' => 'Un membre de moins de 15 ans est noté caregiver.',
        'suggestion' => 'Vérifier l\'âge du membre ou corriger « caregiver ».',
    ];

    public function up(): void
    {
        $rule = DB::table('quality_rules')->where('code', 'MAJ_11')->first();
        if (! $rule) {
            return;
        }
        $new = BeninRuleSet::rules()['MAJ_11'];
        DB::table('quality_rules')->where('id', $rule->id)->update([
            'category' => 'metier',
            'handler' => $new['handler'],
            'expression' => null,
            'variables' => $new['variables'],
            'message' => in_array($rule->message, [null, self::PREVIOUS['message']], true) ? $new['message'] : $rule->message,
            'suggestion' => in_array($rule->suggestion, [null, self::PREVIOUS['suggestion']], true) ? $new['suggestion'] : $rule->suggestion,
            'description' => $new['message'].' (règle MAJ-11 du document qualité Bénin)',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('quality_rules')->where('code', 'MAJ_11')->update([
            'category' => 'expression', 'handler' => null, 'expression' => self::PREVIOUS['expression'], 'variables' => 'person_caregiver,age_group',
            'message' => self::PREVIOUS['message'], 'suggestion' => self::PREVIOUS['suggestion'], 'updated_at' => now(),
        ]);
    }
};
