<?php

namespace App\Services\Quality\Rules\Dictionary;

use App\Services\Quality\QueryCandidate;
use App\Services\Quality\Scope;
use App\Services\Quality\SubmissionContext;
use DateTimeImmutable;

class TypeRule extends DictionaryRule
{
    private const TYPES = ['integer', 'decimal', 'date', 'datetime', 'geopoint'];

    protected function applies(array $variable, SubmissionContext $context): bool
    {
        return in_array($variable['base_type'], self::TYPES, true);
    }

    protected function check(SubmissionContext $context, Scope $scope, string $name, array $variable, mixed $value): ?QueryCandidate
    {
        if (self::isEmpty($value) || self::valid($variable['base_type'], trim((string) $value))) {
            return null;
        }

        return QueryCandidate::for($scope, $scope->label().' – '.self::label($variable).' : valeur incompatible avec le type « '.$variable['base_type'].' »', $name, $value, self::expected($variable['base_type']));
    }

    public static function valid(string $type, string $value): bool
    {
        return match ($type) {
            'integer' => (bool) preg_match('/^-?\d+$/', $value),
            'decimal' => is_numeric($value),
            'date' => self::validDate(substr($value, 0, 10)) && (strlen($value) === 10 || str_contains($value, 'T')),
            'datetime' => self::validDate(substr($value, 0, 10)),
            'geopoint' => self::validGeopoint($value),
            default => true,
        };
    }

    private static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function validGeopoint(string $value): bool
    {
        $parts = preg_split('/\s+/', $value);
        if (count($parts) < 2 || count($parts) > 4) {
            return false;
        }
        foreach ($parts as $part) {
            if (! is_numeric($part)) {
                return false;
            }
        }

        return abs((float) $parts[0]) <= 90 && abs((float) $parts[1]) <= 180;
    }

    private static function expected(string $type): string
    {
        return match ($type) {
            'integer' => 'Nombre entier',
            'decimal' => 'Nombre décimal',
            'date' => 'Date AAAA-MM-JJ',
            'datetime' => 'Date et heure ISO 8601',
            'geopoint' => 'latitude longitude [altitude précision]',
        };
    }
}
