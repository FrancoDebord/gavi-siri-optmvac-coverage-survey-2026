<?php

namespace App\Services\Dictionary;

use App\Models\FormChoice;
use App\Models\FormVariable;
use App\Models\FormVersion;

/**
 * Vue en mémoire du dictionnaire actif : variables par nom, listes de choix,
 * chaîne des conditions « relevant » héritées des groupes parents.
 */
class Dictionary
{
    private static ?self $instance = null;

    /** @var array<string, array<string, mixed>> */
    private array $variables = [];

    /** @var array<string, array<string, string|null>> list_name => [name => label] */
    private array $choices = [];

    /** @var array<string, array<string, mixed>> groupes/repeats par nom */
    private array $groups = [];

    public function __construct(public readonly ?FormVersion $formVersion)
    {
        if (! $formVersion) {
            return;
        }

        foreach (FormVariable::where('form_version_id', $formVersion->id)->orderBy('position')->get() as $variable) {
            $row = $variable->only([
                'name', 'type', 'base_type', 'list_name', 'label', 'required', 'constraint',
                'constraint_message', 'relevant', 'calculation', 'group_path', 'repeat_name', 'position', 'choice_filter',
            ]);
            if (in_array($variable->base_type, ['begin group', 'begin repeat'], true)) {
                $this->groups[$variable->name] = $row;
            } elseif (! in_array($variable->base_type, ['end group', 'end repeat'], true) && $variable->name !== '') {
                $this->variables[$variable->name] = $row;
            }
        }

        foreach (FormChoice::where('form_version_id', $formVersion->id)->get(['list_name', 'name', 'label']) as $choice) {
            $this->choices[$choice->list_name][$choice->name] = $choice->label;
        }
    }

    public static function current(): self
    {
        return self::$instance ??= new self(FormVersion::active());
    }

    public static function flush(): void
    {
        self::$instance = null;
    }

    public function isEmpty(): bool
    {
        return $this->variables === [];
    }

    /** @return array<string, array<string, mixed>> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function variable(string $name): ?array
    {
        return $this->variables[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->variables[$name]) || isset($this->groups[$name]);
    }

    public function isRepeat(string $name): bool
    {
        return ($this->groups[$name]['base_type'] ?? null) === 'begin repeat';
    }

    /** @return list<string> */
    public function repeatNames(): array
    {
        return array_keys(array_filter($this->groups, fn ($g) => $g['base_type'] === 'begin repeat'));
    }

    /** @return array<string, string|null>|null */
    public function choices(string $listName): ?array
    {
        return $this->choices[$listName] ?? null;
    }

    /**
     * Choix d'une liste avec leurs colonnes supplémentaires (filtres en cascade des listes « from_file » :
     * l2 pour un arrondissement, parent pour un village…).
     *
     * @return list<array{name: string, label: ?string, extra: array}>
     */
    public function choiceRows(string $listName): array
    {
        if (! $this->formVersion) {
            return [];
        }

        return $this->choiceRows[$listName] ??= FormChoice::where('form_version_id', $this->formVersion->id)->where('list_name', $listName)
            ->orderBy('id')->get(['name', 'label', 'extra'])
            ->map(fn ($c) => ['name' => (string) $c->name, 'label' => $c->label, 'extra' => (array) ($c->extra ?? [])])->all();
    }

    /** @var array<string, list<array>> */
    private array $choiceRows = [];

    public function choiceLabel(string $listName, string $value): ?string
    {
        return $this->choices[$listName][$value] ?? null;
    }

    /**
     * Conditions « relevant » à satisfaire pour qu'une variable soit affichée :
     * celles de chaque groupe parent puis la sienne.
     *
     * @return list<string>
     */
    public function relevantChain(string $name): array
    {
        return array_column($this->relevantChainDetailed($name), 'expression');
    }

    /**
     * Même chaîne, en indiquant pour chaque condition le groupe qui la porte
     * (null pour la condition propre à la variable) et le repeat de ce groupe.
     *
     * @return list<array{group: string|null, repeat: string|null, expression: string}>
     */
    public function relevantChainDetailed(string $name): array
    {
        $variable = $this->variables[$name] ?? $this->groups[$name] ?? null;
        if (! $variable) {
            return [];
        }

        $chain = [];
        foreach (array_filter(explode('/', (string) $variable['group_path'])) as $group) {
            if (! empty($this->groups[$group]['relevant'])) {
                $repeat = $this->groups[$group]['base_type'] === 'begin repeat' ? $group : $this->groups[$group]['repeat_name'];
                $chain[] = ['group' => $group, 'repeat' => $repeat, 'expression' => $this->groups[$group]['relevant']];
            }
        }
        if (! empty($variable['relevant'])) {
            $chain[] = ['group' => null, 'repeat' => $variable['repeat_name'], 'expression' => $variable['relevant']];
        }

        return $chain;
    }

    public function group(string $name): ?array
    {
        return $this->groups[$name] ?? null;
    }

    /** Variables qui contiennent une donnée saisie (pas de note, calcul, groupe…). */
    public function isInput(array $variable): bool
    {
        return ! in_array($variable['base_type'], [
            'note', 'calculate', 'hidden', 'start', 'end', 'today', 'deviceid', 'phonenumber',
            'username', 'simserial', 'subscriberid', 'audit', 'begin group', 'end group',
            'begin repeat', 'end repeat', 'image', 'audio', 'video', 'file', 'acknowledge',
        ], true);
    }

    /**
     * Le groupe ou repeat est-il hors de toute section (ex. dans un repeat) ?
     * Renvoie la liste des repeats englobants, du plus externe au plus interne.
     *
     * @return list<string>
     */
    public function repeatPath(string $name): array
    {
        $variable = $this->variables[$name] ?? $this->groups[$name] ?? null;
        if (! $variable) {
            return [];
        }

        return array_values(array_filter(
            explode('/', (string) $variable['group_path']),
            fn ($g) => $g !== '' && $this->isRepeat($g),
        ));
    }
}
