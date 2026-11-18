<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\QualityRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Paramètres de l'application (administrateur) : gestion des queries. Chaque modification est tracée. */
class SettingsController extends Controller
{
    public function edit(): View
    {
        return view('settings.edit', [
            'autoAssign' => AppSetting::get(AppSetting::AUTO_ASSIGN),
            'dueDays' => (int) AppSetting::get(AppSetting::DUE_DAYS),
            'doubleValidation' => (array) AppSetting::get(AppSetting::DOUBLE_VALIDATION),
            'autoApply' => (bool) AppSetting::get(AppSetting::AUTO_APPLY),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'auto_assign' => ['required', Rule::in(array_keys(AppSetting::AUTO_ASSIGN_OPTIONS))],
            'due_days' => ['required', 'integer', 'min:0', 'max:60'],
            'double_validation' => ['nullable', 'array'],
            'double_validation.*' => [Rule::in(array_keys(QualityRule::SEVERITIES))],
        ], [], ['auto_assign' => 'affectation automatique', 'due_days' => 'échéance', 'double_validation' => 'double validation']);

        $values = [
            AppSetting::AUTO_ASSIGN => $data['auto_assign'],
            AppSetting::DUE_DAYS => (int) $data['due_days'],
            AppSetting::DOUBLE_VALIDATION => array_values($data['double_validation'] ?? []),
            AppSetting::AUTO_APPLY => $request->boolean('auto_apply'),
        ];
        $changes = [];
        foreach ($values as $key => $value) {
            $before = AppSetting::get($key);
            if ($before !== $value) {
                AppSetting::put($key, $value, $request->user());
                $changes[$key] = ['avant' => $before, 'apres' => $value];
            }
        }
        if ($changes) {
            AuditLog::record('settings.updated', null, $changes);
        }

        return back()->with('success', $changes ? 'Paramètres enregistrés.' : 'Aucune modification.');
    }
}
