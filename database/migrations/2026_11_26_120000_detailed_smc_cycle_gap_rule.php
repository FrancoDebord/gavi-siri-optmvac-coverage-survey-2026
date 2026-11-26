<?php

use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-38 passe d'une expression à une règle dédiée : la query liste toutes les dates de cycles CPS et nomme les cycles
 * trop rapprochés ; la fiche propose de corriger chaque date saisie, ou de confirmer (erreur sur la carte CPS). Texte et
 * suggestion mis à jour s'ils n'ont pas été personnalisés. Les queries gardent leur identité (même enfant, date_1).
 */
return new class extends Migration
{
    private const MESSAGE = 'Deux cycles CPS à moins de 3 semaines.';

    private const SUGGESTION = 'Relire la carte CPS et corriger.';

    public function up(): void
    {
        $rule = DB::table('quality_rules')->where('code', 'MAJ_38')->first();
        if (! $rule) {
            return;
        }
        $new = BeninRuleSet::rules()['MAJ_38'];
        DB::table('quality_rules')->where('id', $rule->id)->update([
            'category' => 'metier',
            'handler' => $new['handler'],
            'expression' => null,
            'variables' => $new['variables'],
            'message' => in_array($rule->message, [null, self::MESSAGE], true) ? $new['message'] : $rule->message,
            'suggestion' => in_array($rule->suggestion, [null, self::SUGGESTION], true) ? $new['suggestion'] : $rule->suggestion,
            'description' => $new['message'].' (règle MAJ-38 du document qualité Bénin)',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $expression = implode(' or ', array_map(fn ($k) => "(\${date_{$k}} - \${date_".($k - 1)."} > 0 and \${date_{$k}} - \${date_".($k - 1).'} < 21)', range(2, 5)));
        DB::table('quality_rules')->where('code', 'MAJ_38')->update([
            'category' => 'expression', 'handler' => null, 'expression' => $expression,
            'message' => self::MESSAGE, 'suggestion' => self::SUGGESTION, 'updated_at' => now(),
        ]);
    }
};
