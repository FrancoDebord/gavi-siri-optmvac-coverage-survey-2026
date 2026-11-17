<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueryAssignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign queries');
    }

    public function rules(): array
    {
        return [
            'ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return ['ids' => 'sélection', 'assigned_to' => 'assigné', 'due_on' => 'échéance', 'comment' => 'commentaire'];
    }
}
