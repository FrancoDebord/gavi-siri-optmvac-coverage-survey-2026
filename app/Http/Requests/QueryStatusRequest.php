<?php

namespace App\Http\Requests;

use App\Models\DataQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QueryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process queries');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_keys(DataQuery::STATUSES))],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['status' => 'statut', 'comment' => 'commentaire'];
    }
}
