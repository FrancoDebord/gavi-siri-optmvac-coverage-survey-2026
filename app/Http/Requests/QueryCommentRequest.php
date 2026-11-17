<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QueryCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Droits vérifiés sur la query elle-même (politique : assigné, équipe de traitement, data manager).
        return true;
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
