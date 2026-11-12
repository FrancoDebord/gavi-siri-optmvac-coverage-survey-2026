<?php

namespace App\Services\Quality\Expression;

/**
 * Analyseur d'expressions XLSForm (sous-ensemble XPath 1.0) vers un AST.
 *
 * Grammaire (priorité croissante) :
 *   or → and → (=, !=) → (<, <=, >, >=) → (+, -) → (*, div, mod) → unaire (-) → primaire
 * Primaire : nombre, chaîne, ${var}, ., .., fonction(args), (expr)
 *
 * Nœuds : ['lit', v] | ['var', name] | ['self'] | ['bin', op, a, b] | ['neg', a] | ['call', fn, args]
 */
class ExpressionParser
{
    /** @var array<string, array> */
    private static array $cache = [];

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $pos = 0;

    public static function parse(string $expression): array
    {
        $expression = trim($expression);

        return self::$cache[$expression] ??= (new self)->run($expression);
    }

    private function run(string $expression): array
    {
        if ($expression === '') {
            throw new ExpressionException('Expression vide.');
        }
        $this->tokens = $this->tokenize($expression);
        $this->pos = 0;
        $ast = $this->parseOr();
        if ($this->peek()[0] !== 'eof') {
            throw new ExpressionException("Jeton inattendu « {$this->peek()[1]} » dans : {$expression}");
        }

        return $ast;
    }

    /** @return list<array{0: string, 1: mixed}> */
    private function tokenize(string $s): array
    {
        $tokens = [];
        $length = strlen($s);
        $i = 0;

        while ($i < $length) {
            $c = $s[$i];

            if (ctype_space($c)) {
                $i++;

                continue;
            }
            if ($c === '$' && ($s[$i + 1] ?? '') === '{') {
                $end = strpos($s, '}', $i);
                if ($end === false) {
                    throw new ExpressionException('Référence ${…} non fermée.');
                }
                $tokens[] = ['var', trim(substr($s, $i + 2, $end - $i - 2))];
                $i = $end + 1;

                continue;
            }
            if ($c === '"' || $c === "'") {
                $end = strpos($s, $c, $i + 1);
                if ($end === false) {
                    throw new ExpressionException('Chaîne non fermée.');
                }
                $tokens[] = ['str', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }
            // Guillemets typographiques (UTF-8 multioctet) fréquents dans les XLSForm saisis sous Excel.
            foreach (['“' => '”', '‘' => '’'] as $open => $close) {
                if (substr($s, $i, strlen($open)) === $open) {
                    $end = strpos($s, $close, $i + strlen($open));
                    if ($end === false) {
                        throw new ExpressionException('Chaîne non fermée.');
                    }
                    $tokens[] = ['str', substr($s, $i + strlen($open), $end - $i - strlen($open))];
                    $i = $end + strlen($close);

                    continue 2;
                }
            }
            if (ctype_digit($c) || ($c === '.' && ctype_digit($s[$i + 1] ?? ''))) {
                preg_match('/\G\d*\.?\d+(?:[eE][-+]?\d+)?|\G\d+\.?/', $s, $m, 0, $i);
                $tokens[] = ['num', (float) $m[0]];
                $i += strlen($m[0]);

                continue;
            }
            if ($c === '.') {
                if (($s[$i + 1] ?? '') === '.') {
                    $tokens[] = ['parent', '..'];
                    $i += 2;
                } else {
                    $tokens[] = ['self', '.'];
                    $i++;
                }

                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['!=', '<=', '>='], true)) {
                $tokens[] = ['op', $two];
                $i += 2;

                continue;
            }
            if (in_array($c, ['=', '<', '>', '+', '-', '*', '|'], true)) {
                $tokens[] = ['op', $c];
                $i++;

                continue;
            }
            if ($c === '(' || $c === ')' || $c === ',') {
                $tokens[] = [$c, $c];
                $i++;

                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_:\-]*/', $s, $m, 0, $i)) {
                $word = $m[0];
                // « a-b » sans espace : on ne garde le tiret que s'il est suivi d'une lettre (count-selected).
                $word = preg_replace('/-+$/', '', $word);
                $lower = strtolower($word);
                $tokens[] = in_array($lower, ['and', 'or', 'div', 'mod'], true) ? ['op', $lower] : ['ident', $word];
                $i += strlen($word);

                continue;
            }

            throw new ExpressionException("Caractère inattendu « {$c} ».");
        }

        $tokens[] = ['eof', null];

        return $tokens;
    }

    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    private function next(): array
    {
        return $this->tokens[$this->pos++];
    }

    private function acceptOp(array $ops): ?string
    {
        $token = $this->peek();
        if ($token[0] === 'op' && in_array($token[1], $ops, true)) {
            $this->pos++;

            return $token[1];
        }

        return null;
    }

    private function parseOr(): array
    {
        $left = $this->parseAnd();
        while ($this->acceptOp(['or'])) {
            $left = ['bin', 'or', $left, $this->parseAnd()];
        }

        return $left;
    }

    private function parseAnd(): array
    {
        $left = $this->parseEquality();
        while ($this->acceptOp(['and'])) {
            $left = ['bin', 'and', $left, $this->parseEquality()];
        }

        return $left;
    }

    private function parseEquality(): array
    {
        $left = $this->parseRelational();
        while ($op = $this->acceptOp(['=', '!='])) {
            $left = ['bin', $op, $left, $this->parseRelational()];
        }

        return $left;
    }

    private function parseRelational(): array
    {
        $left = $this->parseAdditive();
        while ($op = $this->acceptOp(['<', '<=', '>', '>='])) {
            $left = ['bin', $op, $left, $this->parseAdditive()];
        }

        return $left;
    }

    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();
        while ($op = $this->acceptOp(['+', '-'])) {
            $left = ['bin', $op, $left, $this->parseMultiplicative()];
        }

        return $left;
    }

    private function parseMultiplicative(): array
    {
        $left = $this->parseUnary();
        while ($op = $this->acceptOp(['*', 'div', 'mod'])) {
            $left = ['bin', $op, $left, $this->parseUnary()];
        }

        return $left;
    }

    private function parseUnary(): array
    {
        if ($this->acceptOp(['-'])) {
            return ['neg', $this->parseUnary()];
        }

        return $this->parsePrimary();
    }

    private function parsePrimary(): array
    {
        $token = $this->next();

        switch ($token[0]) {
            case 'num':
            case 'str':
                return ['lit', $token[1]];
            case 'var':
                return ['var', $token[1]];
            case 'self':
                return ['self'];
            case 'parent':
                return ['call', 'position', []]; // « .. » n'apparaît que dans position(..)
            case '(':
                $expr = $this->parseOr();
                $this->expect(')');

                return $expr;
            case 'ident':
                if ($this->peek()[0] !== '(') {
                    // Chemin XPath nu (ex. /data/x) : non pris en charge.
                    throw new ExpressionException("Référence non prise en charge : {$token[1]}");
                }
                $this->next();
                $args = [];
                if ($this->peek()[0] !== ')') {
                    do {
                        $args[] = $this->parseOr();
                    } while ($this->peek()[0] === ',' && $this->next());
                }
                $this->expect(')');

                return ['call', strtolower($token[1]), $args];
        }

        throw new ExpressionException('Expression incomplète ou jeton inattendu « '.($token[1] ?? 'fin').' ».');
    }

    private function expect(string $type): void
    {
        if ($this->next()[0] !== $type) {
            throw new ExpressionException("« {$type} » attendu.");
        }
    }
}
