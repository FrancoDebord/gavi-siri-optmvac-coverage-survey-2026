<?php

namespace App\Http\Requests;

use App\Models\FieldDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FieldDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage decisions');
    }

    public function rules(): array
    {
        $decision = $this->route('decision');

        return [
            'decision_category_id' => ['required', Rule::exists('decision_categories', 'id')->where('is_active', true)],
            'decided_on' => ['required', 'date', 'before_or_equal:today'],
            'effective_from' => ['nullable', 'date'],
            'title' => ['required', 'string', 'max:200'],
            'context' => ['nullable', 'string', 'max:5000'],
            'decision' => ['required', 'string', 'max:5000'],
            'decided_by' => ['nullable', 'string', 'max:150'],
            'health_zone_id' => ['nullable', 'integer', 'exists:health_zones,id'],
            'village_id' => ['nullable', 'integer', 'exists:villages,id'],
            'cluster_id' => ['nullable', 'integer', 'exists:clusters,id'],
            'status' => [$decision ? 'required' : 'nullable', Rule::in(array_keys(FieldDecision::STATUSES))],
            'replaced_by_id' => ['nullable', Rule::requiredIf(fn () => $this->input('status') === FieldDecision::STATUS_REPLACED), 'integer', 'exists:field_decisions,id',
                Rule::notIn(array_filter([$decision?->id]))],
            'replaces_id' => ['nullable', 'integer', 'exists:field_decisions,id'],
            'notify' => ['nullable', 'boolean'],
            'in_summary' => ['nullable', 'boolean'],
            'is_confidential' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'decision_category_id' => 'catégorie', 'decided_on' => 'date de la décision', 'effective_from' => 'date d\'application',
            'title' => 'titre', 'context' => 'contexte', 'decision' => 'décision', 'decided_by' => 'décidé par',
            'health_zone_id' => 'zone sanitaire', 'village_id' => 'village', 'cluster_id' => 'grappe', 'status' => 'statut',
            'replaced_by_id' => 'décision de remplacement', 'replaces_id' => 'décision remplacée',
        ];
    }

    public function messages(): array
    {
        return ['replaced_by_id.required' => 'Indiquez la décision qui remplace celle-ci.', 'replaced_by_id.not_in' => 'Une décision ne peut pas se remplacer elle-même.'];
    }
}
