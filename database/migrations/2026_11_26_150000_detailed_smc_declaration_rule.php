<?php

use App\Services\Quality\BeninRuleSet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-41 passe d'une expression à une règle dédiée : la query donne la déclaration du caregiver et les cycles cochés
 * sur la carte CPS (avec leurs dates) ; la fiche propose de corriger la déclaration, les cycles, les deux, ou rien.
 * Texte et suggestion mis à jour s'ils n'ont pas été personnalisés. Les queries gardent leur identité (child_got_smc).
 */
return new class extends Migration
{
    private const MESSAGE = 'La carte montre une CPS que le caregiver n\'a pas déclarée.';

    private const SUGGESTION = 'Revoir avec le caregiver et corriger.';

    public function up(): void
    {
        $rule = DB::table('quality_rules')->where('code', 'MAJ_41')->first();
        if (! $rule) {
            return;
        }
        $new = BeninRuleSet::rules()['MAJ_41'];
        DB::table('quality_rules')->where('id', $rule->id)->update([
            'category' => 'metier',
            'handler' => $new['handler'],
            'expression' => null,
            'variables' => $new['variables'],
            'message' => in_array($rule->message, [null, self::MESSAGE], true) ? $new['message'] : $rule->message,
            'suggestion' => in_array($rule->suggestion, [null, self::SUGGESTION], true) ? $new['suggestion'] : $rule->suggestion,
            'description' => $new['message'].' (règle MAJ-41 du document qualité Bénin)',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $expression = '${child_got_smc} = 0 and ('.implode(' or ', array_map(fn ($k) => "\${treated_{$k}} = 1", range(1, 5))).')';
        DB::table('quality_rules')->where('code', 'MAJ_41')->update([
            'category' => 'expression', 'handler' => null, 'expression' => $expression, 'variables' => 'child_got_smc,treated_1',
            'message' => self::MESSAGE, 'suggestion' => self::SUGGESTION, 'updated_at' => now(),
        ]);
    }
};
