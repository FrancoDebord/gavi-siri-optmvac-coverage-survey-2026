<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueryCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('process queries');
    }

    public function rules(): array
    {
        return ['comment' => ['required', 'string', 'max:2000']];
    }

    public function attributes(): array
    {
        return ['comment' => 'commentaire'];
    }
}
