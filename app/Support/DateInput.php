<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** Dates saisies en jj/mm/aaaa (interface) ou aaaa-mm-jj (champs date HTML) → aaaa-mm-jj. */
final class DateInput
{
    public static function parse(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        try {
            if (preg_match('#^\d{2}/\d{2}/\d{4}$#', $value)) {
                return CarbonImmutable::createFromFormat('!d/m/Y', $value)->toDateString();
            }
            if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $value)) {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value)->toDateString();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    public static function display(mixed $value): string
    {
        if (! $value) {
            return '—';
        }

        return CarbonImmutable::parse($value)->format('d/m/Y');
    }
}
