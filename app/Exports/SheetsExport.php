<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Classeur de plusieurs feuilles (ArrayExport). */
class SheetsExport implements WithMultipleSheets
{
    /** @param list<ArrayExport> $sheets */
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        return $this->sheets;
    }
}
