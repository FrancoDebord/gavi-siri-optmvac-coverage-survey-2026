<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\VariableMapping;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\VariableMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MappingController extends Controller
{
    public function edit(): View
    {
        VariableMap::seedDefaults();
        $dictionary = Dictionary::current();

        return view('mapping.edit', [
            'mappings' => VariableMapping::orderBy('sort')->get()->groupBy('category'),
            'variables' => collect($dictionary->variables())->map(fn ($v) => ['name' => $v['name'], 'label' => $v['label'], 'type' => $v['base_type']])->values(),
            'repeats' => $dictionary->repeatNames(),
            'dictionary' => $dictionary,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*.variable_name' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_.\-]*$/'],
            'mapping.*.value' => ['nullable', 'string', 'max:100'],
        ], [], ['mapping.*.variable_name' => 'variable']);

        $changes = [];
        foreach ($data['mapping'] as $key => $row) {
            $mapping = VariableMapping::where('key', $key)->first();
            if (! $mapping) {
                continue;
            }
            $mapping->fill(['variable_name' => $row['variable_name'] ?? null, 'value' => $row['value'] ?? null]);
            if ($mapping->isDirty()) {
                $changes[$key] = $mapping->getDirty();
                $mapping->save();
            }
        }
        VariableMap::flush();
        AuditLog::record('mapping.updated', null, $changes);

        return back()->with('success', count($changes).' association(s) modifiée(s). Réimportez ou resynchronisez les données si des variables géographiques ont changé.');
    }
}
