<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage users');
    }

    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'username' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user?->id)],
            'role' => ['required', Rule::in(array_keys(User::ROLES))],
            'health_zone_id' => ['nullable', 'exists:health_zones,id', Rule::requiredIf(fn () => $this->input('role') === User::ROLE_SUPERVISOR && ! $this->input('commune_id'))],
            'commune_id' => ['nullable', 'exists:communes,id'],
            'team_id' => ['nullable', 'exists:teams,id', Rule::requiredIf(fn () => $this->input('role') === User::ROLE_TEAM)],
            'is_active' => ['boolean'],
            'send_access' => ['boolean'],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nom', 'role' => 'rôle', 'health_zone_id' => 'zone sanitaire', 'commune_id' => 'commune', 'team_id' => 'binôme', 'password' => 'mot de passe'];
    }

    public function messages(): array
    {
        return [
            'health_zone_id.required' => 'Un superviseur doit être rattaché à une zone sanitaire ou à une commune.',
            'team_id.required' => 'Un compte binôme doit être rattaché à un binôme.',
        ];
    }
}
