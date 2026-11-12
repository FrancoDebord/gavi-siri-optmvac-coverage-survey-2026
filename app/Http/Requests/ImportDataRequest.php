<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import data');
    }

    public function rules(): array
    {
        return [
            'main_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:102400'],
            'repeat_files' => ['nullable', 'array', 'max:10'],
            'repeat_files.*' => ['file', 'mimes:csv,txt,xlsx,xls', 'max:102400'],
        ];
    }

    public function attributes(): array
    {
        return ['main_file' => 'fichier principal', 'repeat_files' => 'fichiers des repeats'];
    }
}
