<?php

namespace App\Http\Requests;

use App\Models\FieldIncident;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FieldIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('report incidents');
    }

    public function rules(): array
    {
        return [
            'health_zone_id' => [Rule::requiredIf(! $this->user()->geographicScope()), 'nullable', 'integer', 'exists:health_zones,id'],
            'occurred_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', Rule::in(array_keys(FieldIncident::CATEGORIES))],
            'severity' => ['required', Rule::in(array_keys(FieldIncident::SEVERITIES))],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:5000'],
            'impact' => ['nullable', 'string', 'max:2000'],
            'village_id' => ['nullable', 'integer', 'exists:villages,id'],
            'cluster_id' => ['nullable', 'integer', 'exists:clusters,id'],
            'collector_id' => ['nullable', 'integer', 'exists:collectors,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'health_zone_id' => 'zone sanitaire', 'occurred_on' => 'date de l\'incident', 'category' => 'type d\'incident',
            'severity' => 'gravité', 'title' => 'titre', 'description' => 'description', 'impact' => 'conséquence sur la collecte',
            'village_id' => 'village', 'cluster_id' => 'grappe', 'collector_id' => 'agent',
        ];
    }
}
