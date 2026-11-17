<?php

namespace App\Http\Controllers;

use App\Http\Requests\QualityRuleRequest;
use App\Models\AuditLog;
use App\Models\DataQuery;
use App\Models\Household;
use App\Models\QualityRule;
use App\Models\QualityRun;
use App\Services\Dictionary\Dictionary;
use App\Services\Quality\Expression\ExpressionException;
use App\Services\Quality\Expression\ExpressionParser;
use App\Services\Quality\RuleCatalog;
use App\Services\Quality\SubmissionContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class QualityRuleController extends Controller
{
    public function index(): View
    {
        $counts = DataQuery::selectRaw('rule_code, count(*) as total, sum(case when status in (?, ?) then 1 else 0 end) as open', DataQuery::UNRESOLVED)
            ->groupBy('rule_code')->get()->keyBy('rule_code');

        return view('rules.index', [
            'rules' => QualityRule::orderByRaw("case category when 'auto' then 1 when 'metier' then 2 else 3 end")->orderBy('code')->get(),
            'counts' => $counts,
            'lastRun' => QualityRun::latest('id')->first(),
        ]);
    }

    public function create(): View
    {
        return view('rules.form', ['rule' => new QualityRule(['category' => 'expression', 'severity' => 'majeure', 'scope' => 'household', 'is_active' => true]), 'defaults' => []]);
    }

    public function store(QualityRuleRequest $request): RedirectResponse
    {
        $rule = QualityRule::create($request->safe()->except(['parameters', 'kind']) + ['category' => 'expression', 'kind' => $request->validated('kind') ?: QualityRule::KIND_QUERY, 'is_active' => $request->boolean('is_active')]);
        AuditLog::record('rule.created', $rule, ['code' => $rule->code]);

        return redirect()->route('rules.index')->with('success', "Règle {$rule->code} créée. Elle sera appliquée à la prochaine exécution du moteur.");
    }

    public function edit(QualityRule $rule): View
    {
        return view('rules.form', ['rule' => $rule, 'defaults' => RuleCatalog::find($rule->code)['parameters'] ?? []]);
    }

    public function update(QualityRuleRequest $request, QualityRule $rule): RedirectResponse
    {
        $data = $request->validated();
        $defaults = RuleCatalog::find($rule->code)['parameters'] ?? [];
        // Seuls les paramètres connus du catalogue sont modifiables ; valeurs numériques converties.
        $parameters = collect($data['parameters'] ?? [])->only(array_keys($defaults))
            ->map(fn ($v) => is_numeric($v) ? $v + 0 : $v)->filter(fn ($v) => $v !== null && $v !== '')->all();

        $attributes = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'suggestion' => $data['suggestion'] ?? null,
            'severity' => $data['severity'],
            'kind' => $data['kind'] ?? $rule->kind ?? QualityRule::KIND_QUERY,
            'is_active' => $request->boolean('is_active'),
            'parameters' => $parameters ?: null,
        ];
        if ($rule->category === 'expression') {
            $attributes += array_intersect_key($data, array_flip(['scope', 'variables', 'expression', 'message']));
        } elseif ($rule->code === 'OUTLIER') {
            $attributes['variables'] = $data['variables'] ?? null;
        }
        $rule->update($attributes);
        // La sévérité des queries non résolues suit la règle.
        DataQuery::withoutGlobalScope('kind')->where('quality_rule_id', $rule->id)->whereIn('status', DataQuery::UNRESOLVED)->update(['severity' => $rule->severity]);
        // La nature (query / observation) s'applique à toutes les anomalies de la règle, historique conservé.
        if ($rule->wasChanged('kind')) {
            DataQuery::withoutGlobalScope('kind')->where('quality_rule_id', $rule->id)->update(['kind' => $rule->kind]);
        }
        AuditLog::record('rule.updated', $rule, ['code' => $rule->code, 'modifications' => $rule->getChanges()]);

        return redirect()->route('rules.index')->with('success', "Règle {$rule->code} mise à jour.");
    }

    public function toggle(QualityRule $rule): RedirectResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);
        AuditLog::record($rule->is_active ? 'rule.enabled' : 'rule.disabled', $rule, ['code' => $rule->code]);

        return back()->with('success', "Règle {$rule->code} ".($rule->is_active ? 'activée' : 'désactivée').'.');
    }

    public function destroy(QualityRule $rule): RedirectResponse
    {
        abort_unless($rule->category === 'expression', 403, 'Seules les règles personnalisées peuvent être supprimées ; désactivez les autres.');
        AuditLog::record('rule.deleted', $rule, ['code' => $rule->code]);
        $rule->delete();

        return redirect()->route('rules.index')->with('success', 'Règle supprimée.');
    }

    /** Évalue une expression sur un échantillon de soumissions pour aider à la mise au point. */
    public function testExpression(Request $request): JsonResponse
    {
        $data = $request->validate([
            'expression' => ['required', 'string', 'max:2000'],
            'scope' => ['required', Rule::in(array_keys(QualityRule::SCOPES))],
        ]);
        try {
            ExpressionParser::parse($data['expression']);
        } catch (ExpressionException $e) {
            return response()->json(['ok' => false, 'message' => 'Expression invalide : '.$e->getMessage()]);
        }

        $dictionary = Dictionary::current();
        $checked = 0;
        $hits = 0;
        $errors = 0;
        $examples = [];
        foreach (Household::with(['caregivers', 'children'])->latest('id')->limit(300)->get() as $household) {
            $context = new SubmissionContext($household, $dictionary, CarbonImmutable::today());
            foreach ($context->scopes($data['scope']) as $scope) {
                $checked++;
                try {
                    if ($context->evaluator($scope)->evaluateBool($data['expression'])) {
                        $hits++;
                        if (count($examples) < 5) {
                            $examples[] = "Ménage {$household->household_code} – {$scope->label()}";
                        }
                    }
                } catch (ExpressionException) {
                    $errors++;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'message' => "Expression valide. Sur {$checked} instance(s) testée(s) (300 dernières soumissions), {$hits} déclencheraient une query".($errors ? " ; {$errors} non évaluable(s)." : '.'),
            'examples' => $examples,
        ]);
    }
}
