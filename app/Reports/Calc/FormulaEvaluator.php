<?php

declare(strict_types=1);

namespace App\Reports\Calc;

/**
 * A tiny, safe arithmetic evaluator for calculated metrics (CLAUDE.md §10 — "free
 * metrics"). Supports `+ - * /`, parentheses, numeric literals and metric
 * identifiers (e.g. `woocommerce.revenue / ga4.sessions * 100`). Identifiers are
 * resolved from a flat name→number map. **No `eval`** — it tokenizes, converts to
 * RPN (shunting-yard) and evaluates, so a malicious formula can do nothing but math.
 *
 * This is arithmetic over the already-aggregated metric bag, NOT analytics over raw
 * rows — it stays within the product's niche (§3.3), it is not a BI engine.
 */
final class FormulaEvaluator
{
    /**
     * @param  array<string, int|float>  $values  metric name → number
     *
     * @throws FormulaException
     */
    public function evaluate(string $formula, array $values): float
    {
        $rpn = $this->toRpn($this->tokenize($formula));
        $result = $this->evaluateRpn($rpn, $values);

        // Overflow (e.g. huge * huge → INF) or NaN must not be stored in a report —
        // the renderer would choke. Treat it like any other incomputable formula.
        if (! is_finite($result)) {
            throw new FormulaException('Formula produced a non-finite result (overflow or NaN).');
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;

                continue;
            }

            if (str_contains('+-*/()', $char)) {
                $tokens[] = $char;
                $i++;

                continue;
            }

            if (ctype_digit($char) || $char === '.') {
                $number = '';
                while ($i < $length && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    $number .= $formula[$i];
                    $i++;
                }
                $tokens[] = $number;

                continue;
            }

            if (ctype_alpha($char) || $char === '_') {
                $name = '';
                while ($i < $length && (ctype_alnum($formula[$i]) || $formula[$i] === '_' || $formula[$i] === '.')) {
                    $name .= $formula[$i];
                    $i++;
                }
                $tokens[] = $name;

                continue;
            }

            throw new FormulaException("Unexpected character '{$char}' in formula.");
        }

        return $tokens;
    }

    /**
     * Shunting-yard: infix tokens → Reverse Polish Notation.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function toRpn(array $tokens): array
    {
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];
        $output = [];
        $operators = [];

        foreach ($tokens as $token) {
            if (isset($precedence[$token])) {
                while ($operators !== [] && isset($precedence[end($operators)]) && $precedence[end($operators)] >= $precedence[$token]) {
                    $output[] = (string) array_pop($operators);
                }
                $operators[] = $token;
            } elseif ($token === '(') {
                $operators[] = $token;
            } elseif ($token === ')') {
                while ($operators !== [] && end($operators) !== '(') {
                    $output[] = (string) array_pop($operators);
                }
                if ($operators === []) {
                    throw new FormulaException('Mismatched parentheses.');
                }
                array_pop($operators); // discard '('
            } else {
                $output[] = $token; // number or identifier
            }
        }

        while ($operators !== []) {
            $op = (string) array_pop($operators);
            if ($op === '(') {
                throw new FormulaException('Mismatched parentheses.');
            }
            $output[] = $op;
        }

        return $output;
    }

    /**
     * @param  list<string>  $rpn
     * @param  array<string, int|float>  $values
     *
     * @throws FormulaException
     */
    private function evaluateRpn(array $rpn, array $values): float
    {
        /** @var list<float> $stack */
        $stack = [];

        foreach ($rpn as $token) {
            if (isset(['+' => 1, '-' => 1, '*' => 1, '/' => 1][$token])) {
                $right = array_pop($stack);
                $left = array_pop($stack);
                if ($left === null || $right === null) {
                    throw new FormulaException('Invalid expression.');
                }
                $stack[] = $this->apply($token, $left, $right);

                continue;
            }

            $stack[] = $this->resolve($token, $values);
        }

        if (count($stack) !== 1) {
            throw new FormulaException('Invalid expression.');
        }

        return $stack[0];
    }

    /**
     * @param  array<string, int|float>  $values
     */
    private function resolve(string $token, array $values): float
    {
        if (is_numeric($token)) {
            return (float) $token;
        }

        if (! array_key_exists($token, $values)) {
            throw new FormulaException("Unknown metric '{$token}'.");
        }

        return (float) $values[$token];
    }

    private function apply(string $operator, float $left, float $right): float
    {
        return match ($operator) {
            '+' => $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right === 0.0 ? throw new FormulaException('Division by zero.') : $left / $right,
            default => throw new FormulaException("Unknown operator '{$operator}'."),
        };
    }
}
