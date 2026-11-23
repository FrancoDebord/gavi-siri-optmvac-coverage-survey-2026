<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Classeur Excel d'une page de couverture, une feuille par niveau :
 * « Niveau zone » (avec l'ensemble en tête), « Niveau commune » (zone, commune), « Niveau village » (zone, commune, village),
 * puis « Âge et sexe ».
 * $grouped(list<string> $properties) rend les groupes ['labels' => [...], 'children' => n, …indicateurs] ;
 * $columns : [[en-tête, fn(groupe) => valeur, format Excel ou null], …].
 */
class CoverageWorkbook implements WithMultipleSheets
{
    public function __construct(private readonly \Closure $grouped, private readonly array $columns) {}

    public function sheets(): array
    {
        $total = ($this->grouped)([]);
        $zones = array_merge(array_map(fn ($g) => ['labels' => ['Ensemble']] + $g, $total), ($this->grouped)(['zone']));
        $ageSex = array_merge(
            array_map(fn ($g) => ['labels' => ['Tranche d\'âge', $g['labels'][0]]] + $g, ($this->grouped)(['age_group'])),
            array_map(fn ($g) => ['labels' => ['Sexe', $g['labels'][0]]] + $g, ($this->grouped)(['sex'])),
        );

        return [
            $this->sheet('Niveau zone', ['Zone sanitaire'], $zones),
            $this->sheet('Niveau commune', ['Zone sanitaire', 'Commune'], ($this->grouped)(['zone', 'commune'])),
            $this->sheet('Niveau village', ['Zone sanitaire', 'Commune', 'Village'], ($this->grouped)(['zone', 'commune', 'village'])),
            $this->sheet('Âge et sexe', ['Regroupement', 'Libellé'], $ageSex),
        ];
    }

    private function sheet(string $name, array $levels, array $groups): ArrayExport
    {
        $rows = array_map(fn ($g) => [...$g['labels'], $g['children'], ...array_map(fn ($c) => $c[1]($g), $this->columns)], $groups);
        $formats = [];
        foreach ($this->columns as $i => $column) {
            if ($column[2] ?? null) {
                $formats[self::letter(count($levels) + 1 + $i)] = $column[2];
            }
        }

        // Nom de feuille seul (Excel : 31 caractères au plus).
        return new ArrayExport($rows, [...$levels, 'Enfants enrôlés', ...array_column($this->columns, 0)], $name, $formats);
    }

    /** Lettre de colonne Excel (0 → A). */
    private static function letter(int $index): string
    {
        $letters = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letters = chr(65 + $i % 26).$letters;
        }

        return $letters;
    }
}
