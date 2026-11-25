<?php

use App\Models\Household;
use App\Services\Cleaning\CleanDataService;
use App\Services\Cleaning\SubmissionPaths;
use App\Services\Quality\BeninRuleSet;
use App\Services\Quality\Rules\Business\DoseSeriesOrderRule;
use App\Services\Quality\Rules\Business\RecallDoseSeriesOrderRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MAJ-26 (carte) et MAJ-31 (rappel) passent d'une expression à une règle dédiée : chacune contrôle plusieurs séries de
 * doses, mais la query portait toujours sur Penta 1 et montrait les réponses Penta, même quand la dose en cause était
 * RR 2 ou VAP 4. Désormais la query nomme la série et les doses en cause et porte sur la dose reçue sans la précédente.
 *
 * Les queries existantes sont rattachées à cette dose (variable et empreinte recalculées) : elles gardent leur
 * historique et leurs réponses, et une query déjà fermée n'est pas recréée. Le prochain contrôle met leur texte à jour.
 */
return new class extends Migration
{
    /** Règle => [classe, ancienne variable de la query, ancien message, ancienne suggestion]. */
    private const RULES = [
        'MAJ_26' => [DoseSeriesOrderRule::class, 'Penta1,Penta2,Penta3', 'Dose reçue sans la dose précédente sur la carte.', 'Relire la carte et corriger.'],
        'MAJ_31' => [RecallDoseSeriesOrderRule::class, 'penta1_rec,penta2_rec,penta3_rec', 'Rappel incohérent entre les doses.', 'Revoir le rappel avec le caregiver.'],
    ];

    public function up(): void
    {
        foreach (self::RULES as $code => [$class, , $message, $suggestion]) {
            $rule = DB::table('quality_rules')->where('code', $code)->first();
            if (! $rule) {
                continue;
            }
            $new = BeninRuleSet::rules()[$code];
            DB::table('quality_rules')->where('id', $rule->id)->update([
                'category' => 'metier',
                'handler' => $new['handler'],
                'expression' => null,
                'variables' => $new['variables'],
                'message' => in_array($rule->message, [null, $message], true) ? $new['message'] : $rule->message,
                'suggestion' => in_array($rule->suggestion, [null, $suggestion], true) ? $new['suggestion'] : $rule->suggestion,
                'description' => $new['message'].' (règle '.str_replace('_', '-', $code).' du document qualité Bénin)',
                'updated_at' => now(),
            ]);
            $this->rekey($code, $class);
        }
    }

    /** Queries existantes rattachées à la dose reçue sans la précédente (même empreinte que le moteur). */
    private function rekey(string $code, string $class): void
    {
        $cleaning = app(CleanDataService::class);
        $queries = DB::table('data_queries')->where('rule_code', $code)->whereNotNull('household_id')->get(['id', 'household_id', 'instance_path', 'variable']);
        foreach ($queries as $query) {
            try {
                $household = Household::withoutGlobalScopes()->find($query->household_id);
                if (! $household) {
                    continue;
                }
                $clean = $cleaning->cleanSubmission($household);
                $gaps = $class::gaps(function (string $variable) use ($clean, $query) {
                    try {
                        return SubmissionPaths::get($clean, $variable, $query->instance_path);
                    } catch (InvalidArgumentException) {
                        return null;
                    }
                });
            } catch (Throwable) {
                continue;
            }
            // Anomalie disparue : le prochain contrôle ferme la query comme d'habitude.
            if ($gaps === [] || $gaps[0]['dose'] === $query->variable) {
                continue;
            }
            $variable = $gaps[0]['dose'];
            // Même empreinte que QueryCandidate::fingerprint (règle, ménage, membre, variable, discriminant vide).
            $fingerprint = hash('sha256', implode('|', [$code, $query->household_id, $query->instance_path ?? '', $variable, '']));
            if (DB::table('data_queries')->where('fingerprint', $fingerprint)->exists()) {
                continue;
            }
            DB::table('data_queries')->where('id', $query->id)->update(['variable' => $variable, 'fingerprint' => $fingerprint, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::RULES as $code => [$class, $variables, $message, $suggestion]) {
            // Expression d'origine : « dose reçue (1) et dose précédente non reçue (0) », pour chaque série.
            $expression = implode(' or ', array_merge(...array_map(fn (array $s) => array_map(fn ($i) => "(\${{$s[$i]}} = 1 and \${{$s[$i - 1]}} = 0)", range(1, count($s) - 1)), $class::SERIES)));
            DB::table('quality_rules')->where('code', $code)->update([
                'category' => 'expression', 'handler' => null, 'expression' => $expression, 'variables' => $variables,
                'message' => $message, 'suggestion' => $suggestion, 'updated_at' => now(),
            ]);
        }
    }
};
