<?php

namespace App\Http\Requests;

use App\Models\DataQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Droits vérifiés sur la query elle-même (politique : assigné, équipe de traitement, data manager).
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(DataQuery::STATUSES))],
            'comment' => ['nullable', 'string', 'max:2000'],
            'password' => ['nullable', 'string', 'max:255'],
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
