<?php

namespace App\Http\Requests;

use App\Models\QualityRule;
use App\Services\Quality\Expression\ExpressionException;
use App\Services\Quality\Expression\ExpressionParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QualityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage rules');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }

    public function rules(): array
    {
        $rule = $this->route('rule');
        $isExpression = ! $rule || $rule->category === 'expression';

        return [
            'code' => $rule
                ? ['exclude'] // le code d'une règle existante n'est pas modifiable
                : ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_]+$/', Rule::unique('quality_rules', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'severity' => ['required', Rule::in(array_keys(QualityRule::SEVERITIES))],
            'scope' => [$isExpression ? 'required' : 'nullable', Rule::in(array_keys(QualityRule::SCOPES))],
            'variables' => ['nullable', 'string', 'max:255'],
            'expression' => [$isExpression ? 'required' : 'nullable', 'string', 'max:2000', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }
                try {
                    ExpressionParser::parse($value);
                } catch (ExpressionException $e) {
                    $fail('Expression invalide : '.$e->getMessage());
                }
            }],
            'message' => ['nullable', 'string', 'max:255'],
            'suggestion' => ['nullable', 'string', 'max:2000'],
            'parameters' => ['nullable', 'array'],
            'parameters.*' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'libellé', 'severity' => 'sévérité', 'scope' => 'portée', 'expression' => 'expression'];
    }
}
