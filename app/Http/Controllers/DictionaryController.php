<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FormChoice;
use App\Models\FormVersion;
use App\Services\Dictionary\Dictionary;
use App\Services\Dictionary\DictionaryLoader;
use App\Services\Dictionary\VariableMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DictionaryController extends Controller
{
    public function index(Request $request): View
    {
        $versions = FormVersion::latest('id')->get();
        $selected = $request->integer('version') ? $versions->firstWhere('id', $request->integer('version')) : $versions->firstWhere('is_active', true);
        $selected ??= $versions->first();

        $variables = $selected?->variables()->get() ?? collect();
        $choices = $selected
            ? FormChoice::where('form_version_id', $selected->id)->orderBy('list_name')->get()->groupBy('list_name')
            : collect();

        return view('dictionary.index', [
            'versions' => $versions,
            'selected' => $selected,
            'variables' => $variables->reject->isStructural(),
            'types' => $variables->reject->isStructural()->pluck('base_type')->unique()->sort()->values(),
            'repeats' => $variables->pluck('repeat_name')->filter()->unique()->values(),
            'choices' => $choices,
        ]);
    }

    public function upload(Request $request, DictionaryLoader $loader): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480']], [], ['file' => 'fichier XLSForm']);

        $path = $request->file('file')->storeAs('dictionaries', now()->format('Ymd-His').'-'.preg_replace('/[^A-Za-z0-9._-]/', '_', $request->file('file')->getClientOriginalName()));
        try {
            $formVersion = $loader->loadFile(storage_path('app/private/'.$path), 'file');
        } catch (Throwable $e) {
            return back()->with('danger', 'XLSForm illisible : '.$e->getMessage());
        }
        VariableMap::seedDefaults();
        AuditLog::record('dictionary.uploaded', $formVersion, ['fichier' => $request->file('file')->getClientOriginalName()]);

        return redirect()->route('dictionary.index')->with('success', "Dictionnaire {$formVersion->form_id} v{$formVersion->version} chargé ({$formVersion->variables_count} variables) et activé.");
    }

    public function activate(FormVersion $formVersion): RedirectResponse
    {
        $formVersion->activate();
        Dictionary::flush();
        AuditLog::record('dictionary.activated', $formVersion);

        return back()->with('success', "Version {$formVersion->version} activée.");
    }
}
