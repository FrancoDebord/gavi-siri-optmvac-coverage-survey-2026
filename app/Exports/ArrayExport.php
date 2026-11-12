<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Feuille simple : en-têtes en gras, colonnes ajustées, formats optionnels par colonne. */
class ArrayExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  array<string, string>  $formats  lettre de colonne => format (ex. 'H' => '0.0%')
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $headings,
        private readonly string $title = 'Export',
        private readonly array $formats = [],
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        // Excel : 31 caractères maximum, sans : \ / ? * [ ]
        return mb_substr(preg_replace('#[:\\\\/?*\[\]]#', ' ', $this->title), 0, 31) ?: 'Feuille';
    }

    public function columnFormats(): array
    {
        return $this->formats;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        return [1 => ['fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'C10202']], 'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]]];
    }
}
