<?php

namespace App\Http\Requests;

use App\Models\DataQuery;
use App\Support\MultipleChoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Droits vérifiés sur la query elle-même (politique : assigné, équipe de traitement, data manager).
        return true;
    }

    /** Choix multiple : les cases cochées deviennent une valeur ODK (« rr-bj opv-bj »). */
    protected function prepareForValidation(): void
    {
        $corrections = $this->input('corrections');
        if (! is_array($corrections)) {
            return;
        }
        foreach ($corrections as $i => $correction) {
            if (is_array($correction) && is_array($correction['new_value'] ?? null)) {
                $corrections[$i]['new_value'] = MultipleChoice::join($correction['new_value']);
            }
        }
        $this->merge(['corrections' => $corrections]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(DataQuery::STATUSES))],
            'comment' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'max:255'],
            'pair_choice' => ['nullable', 'string', 'max:10'],
            'corrections' => ['nullable', 'array', 'max:20'],
            'corrections.*.variable' => ['nullable', 'string', 'max:255'],
            'corrections.*.instance_path' => ['nullable', 'string', 'max:255'],
            'corrections.*.new_value' => ['nullable', 'string', 'max:2000'],
            'corrections.*.reason' => ['nullable', 'string', 'max:30'],
            'corrections.*.source_reference' => ['nullable', 'string', 'max:255'],
            'corrections.*.comment' => ['nullable', 'string', 'max:2000'],
            'corrections.*.optional' => ['nullable', 'boolean'],
            'corrections.*.group' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'statut', 'comment' => 'commentaire'];
    }
}
