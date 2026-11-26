<?php

use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-29 passe d'une expression à une règle dédiée : la query donne les dates de VAP 3 et de VAP 4 et leur écart, et la
 * fiche propose de corriger l'une ou l'autre. Texte et suggestion mis à jour s'ils n'ont pas été personnalisés. Les
 * queries gardent leur identité (même enfant, variable mal3date) : le prochain contrôle met leur message à jour.
 */
return new class extends Migration
{
    private const PREVIOUS = [
        'expression' => '${mal4date} - ${mal3date} < 150',
        'message' => 'VAP 4 moins de 150 jours après VAP 3.',
        'suggestion' => 'Relire les deux dates sur la carte.',
    ];

    public function up(): void
    {
        $rule = DB::table('quality_rules')->where('code', 'MAJ_29')->first();
        if (! $rule) {
            return;
        }
        $new = BeninRuleSet::rules()['MAJ_29'];
        DB::table('quality_rules')->where('id', $rule->id)->update([
            'category' => 'metier',
            'handler' => $new['handler'],
            'expression' => null,
            'variables' => $new['variables'],
            'message' => in_array($rule->message, [null, self::PREVIOUS['message']], true) ? $new['message'] : $rule->message,
            'suggestion' => in_array($rule->suggestion, [null, self::PREVIOUS['suggestion']], true) ? $new['suggestion'] : $rule->suggestion,
            'description' => $new['message'].' (règle MAJ-29 du document qualité Bénin)',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('quality_rules')->where('code', 'MAJ_29')->update([
            'category' => 'expression', 'handler' => null, 'expression' => self::PREVIOUS['expression'],
            'message' => self::PREVIOUS['message'], 'suggestion' => self::PREVIOUS['suggestion'], 'updated_at' => now(),
        ]);
    }
};
