<?php

namespace App\Http\Requests;

use App\Models\DataQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process queries') || $this->user()->can('validate queries') || $this->user()->can('review queries');
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'status' => ['nullable', Rule::in(array_keys(DataQuery::STATUSES))],
            'comment' => ['nullable', 'string', 'max:2000', 'required_without:status'],
            'password' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['ids' => 'sélection', 'status' => 'statut', 'comment' => 'commentaire'];
    }
}
