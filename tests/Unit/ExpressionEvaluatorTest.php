<?php

namespace Tests\Unit;

use App\Services\Quality\Expression\ExpressionEvaluator;
use App\Services\Quality\Expression\ExpressionException;
use App\Services\Quality\Expression\ExpressionParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpressionEvaluatorTest extends TestCase
{
    private function eval(string $expression, array $values = [], mixed $self = null): mixed
    {
        return ExpressionEvaluator::make($values, $self, CarbonImmutable::parse('2026-10-20'))->evaluate($expression);
    }

    private function bool(string $expression, array $values = [], mixed $self = null): bool
    {
        return ExpressionEvaluator::toBool($this->eval($expression, $values, $self));
    }

    public static function booleanCases(): array
    {
        $values = ['a' => '1', 'b' => '', 'status' => '0', 'm' => '1 99', 'n' => '7', 'age' => '20', 'lo' => '12', 'hi' => '35'];

        return [
            'égalité numérique' => ['${a} = 1', $values, true],
            'égalité chaîne' => ["\${status} = '0'", $values, true],
            'différent de vide' => ["\${a} != ''", $values, true],
            'variable vide' => ["\${b} = ''", $values, true],
            'variable absente = vide' => ["\${inconnue} = ''", $values, true],
            'supérieur' => ['${n} > 5', $values, true],
            'inférieur ou égal' => ['${n} <= 6', $values, false],
            'comparaison avec vide (NaN) fausse' => ['${b} > 3', $values, false],
            'comparaison avec vide (NaN) fausse dans l\'autre sens' => ['${b} < 3', $values, false],
            'and / or' => ['${a} = 1 and (${n} > 10 or ${status} = 0)', $values, true],
            'not()' => ['not(${a} = 2)', $values, true],
            'selected()' => ["selected(\${m}, '99')", $values, true],
            'selected() absent' => ["selected(\${m}, '2')", $values, false],
            'count-selected()' => ['count-selected(${m}) = 2', $values, true],
            'tranche d\'âge' => ['number(${age}) >= ${lo} and number(${age}) <= ${hi}', $values, true],
            'arithmétique' => ['${n} * 2 + 1 = 15', $values, true],
            'div / mod' => ['${n} div 2 = 3.5 and ${n} mod 2 = 1', $values, true],
            'if()' => ["if(\${a} = 1, 'oui', 'non') = 'oui'", $values, true],
            'string-length()' => ["string-length('abc') = 3", $values, true],
            'regex()' => ['regex(\'AB\', "^[A-Za-z]{1,5}$")', $values, true],
            'regex() échec' => ['regex(\'AB12\', "^[A-Za-z]{1,5}$")', $values, false],
            'true() / false()' => ['true() and not(false())', $values, true],
        ];
    }

    #[DataProvider('booleanCases')]
    public function test_boolean_expressions(string $expression, array $values, bool $expected): void
    {
        $this->assertSame($expected, $this->bool($expression, $values));
    }

    public function test_current_value_and_constraints(): void
    {
        $this->assertTrue($this->bool('. <= today()', [], '2026-10-19'));
        $this->assertFalse($this->bool('. <= today()', [], '2026-10-21'));
        $this->assertTrue($this->bool('. >= 0 and . <= if(${child_years}=0,60,11)', ['child_years' => '1'], '11'));
        $this->assertFalse($this->bool('. >= 0 and . <= if(${child_years}=0,60,11)', ['child_years' => '1'], '12'));
        $this->assertTrue($this->bool('regex(., "^[A-Za-z]{1,5}$")', [], 'EA'));
        $this->assertTrue($this->bool('. <= ${num}', ['num' => '6'], '2'));
    }

    public function test_dates_are_compared_as_days(): void
    {
        $values = ['dob' => '2025-01-15', 'vac' => '2025-03-01'];
        $this->assertTrue($this->bool('${vac} > ${dob}', $values));
        $this->assertSame('2026-10-20', $this->eval('today()'));
        $this->assertSame('2025-01-15', $this->eval('date(${dob})', $values));
        // Calcul d'âge du formulaire (age_months_est).
        $months = $this->eval('int((decimal-date-time(today()) - decimal-date-time(${dob})) div 30.4375)', $values);
        $this->assertEquals(21, $months);
    }

    public function test_concat_and_coalesce(): void
    {
        $this->assertSame('bj-bcg', $this->eval("concat(\${c}, '-', 'bcg')", ['c' => 'bj']));
        $this->assertSame('x', $this->eval("coalesce(\${b}, 'x')", ['b' => '']));
    }

    public function test_unsupported_functions_throw(): void
    {
        $this->expectException(ExpressionException::class);
        $this->eval("pulldata('vaccines', 'label', 'name', 'bj-bcg')");
    }

    public function test_parser_rejects_invalid_syntax(): void
    {
        $this->expectException(ExpressionException::class);
        ExpressionParser::parse('${a} = (1');
    }

    public function test_parser_handles_hyphenated_functions_and_paths(): void
    {
        $ast = ExpressionParser::parse('count-selected(${x}) >= 1');
        $this->assertSame('bin', $ast[0]);
        $this->assertSame(['call', 'count-selected', [['var', 'x']]], $ast[2]);
    }
}
