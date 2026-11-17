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
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'statut', 'comment' => 'commentaire'];
    }
}
