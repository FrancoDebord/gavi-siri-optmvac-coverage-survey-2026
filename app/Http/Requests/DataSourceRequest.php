<?php

namespace App\Http\Requests;

use App\Models\DataSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DataSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage sources');
    }

    public function rules(): array
    {
        $odk = fn () => $this->input('type') === DataSource::TYPE_ODK;
        $kobo = fn () => $this->input('type') === DataSource::TYPE_KOBO;
        // En modification, un secret laissé vide conserve la valeur enregistrée.
        $secretRequired = $this->route('source') ? 'nullable' : 'required';

        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(DataSource::TYPES))],
            'server_url' => ['required', 'url:https,http', 'max:255'],
            'email' => [Rule::requiredIf($odk), 'nullable', 'email', 'max:255'],
            'password' => [Rule::when($odk, $secretRequired, 'nullable'), 'nullable', 'string', 'max:255'],
            'project_id' => [Rule::requiredIf($odk), 'nullable', 'string', 'max:50'],
            'form_id' => [Rule::requiredIf($odk), 'nullable', 'string', 'max:255'],
            'token' => [Rule::when($kobo, $secretRequired, 'nullable'), 'nullable', 'string', 'max:255'],
            'asset_uid' => [Rule::requiredIf($kobo), 'nullable', 'string', 'max:64'],
            'sync_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nom', 'server_url' => 'URL du serveur', 'password' => 'mot de passe', 'project_id' => 'Project ID',
            'form_id' => 'Form ID', 'token' => 'token API', 'asset_uid' => 'Asset UID', 'sync_interval_minutes' => 'fréquence de synchronisation',
        ];
    }
}
