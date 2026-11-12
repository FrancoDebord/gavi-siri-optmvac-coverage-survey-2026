<?php

namespace App\Services\Quality\Expression;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * Évalue une expression XLSForm sur une soumission.
 *
 * Les valeurs suivent la sémantique XPath / ODK :
 * - une variable absente vaut la chaîne vide ;
 * - « = » compare numériquement si l'un des opérandes est un nombre, sinon en chaîne ;
 * - « < » « > » convertissent en nombre (les dates deviennent un nombre de jours depuis 1970) ;
 * - number('') est NaN, et toute comparaison avec NaN est fausse (sauf !=).
 *
 * Les fonctions sans équivalent hors du terminal (pulldata, position, instance…)
 * lèvent une ExpressionException : la règle concernée est alors ignorée.
 */
class ExpressionEvaluator
{
    private const DATE_RE = '/^\d{4}-\d{2}-\d{2}(?:[T ][\d:.]+(?:Z|[+-]\d{2}:?\d{2})?)?$/';

    private CarbonImmutable $today;

    /**
     * @param  Closure(string): mixed  $resolver  renvoie la valeur d'une variable ${name}
     * @param  mixed  $self  valeur de « . » (la variable contrôlée)
     */
    public function __construct(
        private readonly Closure $resolver,
        private readonly mixed $self = null,
        ?CarbonImmutable $today = null,
    ) {
        $this->today = $today ?? CarbonImmutable::today();
    }

    public static function make(array $values, mixed $self = null, ?CarbonImmutable $today = null): self
    {
        return new self(fn (string $name) => $values[$name] ?? null, $self, $today);
    }

    public function evaluate(string $expression): mixed
    {
        return $this->eval(ExpressionParser::parse($expression));
    }

    public function evaluateBool(string $expression): bool
    {
        return self::toBool($this->evaluate($expression));
    }

    private function eval(array $node): mixed
    {
        return match ($node[0]) {
            'lit' => $node[1],
            'var' => self::normalize(($this->resolver)($node[1])),
            'self' => self::normalize($this->self),
            'neg' => -self::toNumber($this->eval($node[1])),
            'bin' => $this->binary($node[1], $node[2], $node[3]),
            'call' => $this->call($node[1], $node[2]),
        };
    }

    private function binary(string $op, array $a, array $b): mixed
    {
        if ($op === 'and') {
            return self::toBool($this->eval($a)) && self::toBool($this->eval($b));
        }
        if ($op === 'or') {
            return self::toBool($this->eval($a)) || self::toBool($this->eval($b));
        }

        $left = $this->eval($a);
        $right = $this->eval($b);

        switch ($op) {
            case '=':
            case '!=':
                $equal = $this->equals($left, $right);

                return $op === '=' ? $equal : ! $equal;
            case '<':
            case '<=':
            case '>':
            case '>=':
                $l = self::toNumber($left);
                $r = self::toNumber($right);
                if (is_nan($l) || is_nan($r)) {
                    return false;
                }

                return match ($op) {
                    '<' => $l < $r,
                    '<=' => $l <= $r,
                    '>' => $l > $r,
                    '>=' => $l >= $r,
                };
            case '+':
                return self::toNumber($left) + self::toNumber($right);
            case '-':
                return self::toNumber($left) - self::toNumber($right);
            case '*':
                return self::toNumber($left) * self::toNumber($right);
            case 'div':
                $r = self::toNumber($right);

                return $r == 0 ? NAN : self::toNumber($left) / $r;
            case 'mod':
                $r = self::toNumber($right);

                return $r == 0 ? NAN : fmod(self::toNumber($left), $r);
        }

        throw new ExpressionException("Opérateur inconnu : {$op}");
    }

    private function equals(mixed $left, mixed $right): bool
    {
        if (is_bool($left) || is_bool($right)) {
            return self::toBool($left) === self::toBool($right);
        }
        if (is_float($left) || is_int($left) || is_float($right) || is_int($right)) {
            $l = self::toNumber($left);
            $r = self::toNumber($right);

            return ! is_nan($l) && ! is_nan($r) && abs($l - $r) < 1e-9;
        }

        return (string) $left === (string) $right;
    }

    /** @param list<array> $args */
    private function call(string $name, array $args): mixed
    {
        $arg = fn (int $i) => isset($args[$i]) ? $this->eval($args[$i]) : null;

        switch ($name) {
            case 'not':
                return ! self::toBool($arg(0));
            case 'true':
                return true;
            case 'false':
                return false;
            case 'boolean':
                return self::toBool($arg(0));
            case 'number':
                return self::toNumber($arg(0));
            case 'int':
                $n = self::toNumber($arg(0));

                return is_nan($n) ? NAN : (float) (int) $n;
            case 'round':
                $n = self::toNumber($arg(0));
                $digits = isset($args[1]) ? (int) self::toNumber($arg(1)) : 0;

                return is_nan($n) ? NAN : round($n, $digits);
            case 'floor':
                return floor(self::toNumber($arg(0)));
            case 'ceiling':
                return ceil(self::toNumber($arg(0)));
            case 'abs':
                return abs(self::toNumber($arg(0)));
            case 'string':
                return self::toString($arg(0));
            case 'string-length':
                return (float) mb_strlen(self::toString(isset($args[0]) ? $arg(0) : $this->self));
            case 'normalize-space':
                return trim(preg_replace('/\s+/', ' ', self::toString($arg(0))));
            case 'concat':
                return implode('', array_map(fn ($a) => self::toString($this->eval($a)), $args));
            case 'contains':
                return str_contains(self::toString($arg(0)), self::toString($arg(1)));
            case 'starts-with':
                return str_starts_with(self::toString($arg(0)), self::toString($arg(1)));
            case 'ends-with':
                return str_ends_with(self::toString($arg(0)), self::toString($arg(1)));
            case 'substr':
                $s = self::toString($arg(0));
                $start = (int) self::toNumber($arg(1));
                $end = isset($args[2]) ? (int) self::toNumber($arg(2)) : mb_strlen($s);

                return mb_substr($s, $start, max(0, $end - $start));
            case 'if':
                return self::toBool($arg(0)) ? $arg(1) : $arg(2);
            case 'coalesce':
                $first = $arg(0);

                return self::toString($first) !== '' ? $first : $arg(1);
            case 'once':
                return $arg(0);
            case 'selected':
                return in_array(self::toString($arg(1)), self::splitMultiple($arg(0)), true);
            case 'count-selected':
                return (float) count(self::splitMultiple($arg(0)));
            case 'selected-at':
                return self::splitMultiple($arg(0))[(int) self::toNumber($arg(1))] ?? '';
            case 'regex':
                return $this->regex(self::toString($arg(0)), self::toString($arg(1)));
            case 'today':
                return $this->today->toDateString();
            case 'now':
                return CarbonImmutable::now()->toIso8601String();
            case 'date':
            case 'date-time':
                return self::toDate($arg(0));
            case 'decimal-date-time':
            case 'decimal-date':
                return self::toNumber($arg(0));
            case 'format-date':
            case 'format-date-time':
                return $this->formatDate($arg(0), self::toString($arg(1)));
            case 'min':
            case 'max':
                $values = array_map(fn ($a) => self::toNumber($this->eval($a)), $args);
                if ($values === [] || in_array(true, array_map('is_nan', $values), true)) {
                    return NAN;
                }

                return $name === 'min' ? min($values) : max($values);
        }

        throw new ExpressionException("Fonction non prise en charge : {$name}()");
    }

    private function regex(string $value, string $pattern): bool
    {
        $delimited = '/'.str_replace('/', '\/', $pattern).'/u';
        $result = @preg_match($delimited, $value);
        if ($result === false) {
            throw new ExpressionException("Expression régulière invalide : {$pattern}");
        }

        return $result === 1;
    }

    private function formatDate(mixed $value, string $format): string
    {
        $date = self::toDate($value);
        if ($date === '') {
            return '';
        }
        $map = ['%Y' => 'Y', '%y' => 'y', '%m' => 'm', '%n' => 'n', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%M' => 'i', '%S' => 's'];

        return CarbonImmutable::parse($date)->format(strtr($format, $map));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return implode(' ', $value);
        }

        return $value;
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_float($value) || is_int($value)) {
            return ! is_nan((float) $value) && $value != 0;
        }

        return (string) $value !== '';
    }

    public static function toNumber(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return NAN;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (preg_match(self::DATE_RE, $value)) {
            try {
                $date = CarbonImmutable::parse($value);

                return $date->getTimestamp() / 86400 + $date->getOffset() / 86400;
            } catch (Throwable) {
                return NAN;
            }
        }
        if ($value === 'true') {
            return 1.0;
        }
        if ($value === 'false') {
            return 0.0;
        }

        return NAN;
    }

    public static function toString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return 'NaN';
            }

            return floor($value) == $value && abs($value) < 1e15 ? (string) (int) $value : (string) $value;
        }

        return (string) self::normalize($value);
    }

    /** Date au format Y-m-d (nombre de jours depuis 1970 ou chaîne de date). */
    public static function toDate(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return is_nan((float) $value) ? '' : CarbonImmutable::createFromTimestampUTC((int) round($value * 86400))->toDateString();
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return self::toDate((float) $value);
        }
        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<string> */
    public static function splitMultiple(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return array_values(array_filter(preg_split('/\s+/', trim(self::toString($value))), fn ($v) => $v !== ''));
    }
}
