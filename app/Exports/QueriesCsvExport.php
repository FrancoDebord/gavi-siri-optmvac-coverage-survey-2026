<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Version CSV de l'export des queries : séparateur « ; » et BOM UTF-8 pour Excel en français. */
class QueriesCsvExport implements FromCollection, WithCustomCsvSettings, WithHeadings
{
    public function __construct(private readonly QueriesExport $export) {}

    public function collection()
    {
        return $this->export->rows();
    }

    public function headings(): array
    {
        return QueriesExport::HEADINGS;
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'use_bom' => true];
    }
}
