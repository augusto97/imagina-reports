<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Reports\Calc\FormulaEvaluator;
use App\Reports\Calc\FormulaException;
use PHPUnit\Framework\TestCase;

class FormulaEvaluatorTest extends TestCase
{
    private FormulaEvaluator $eval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eval = new FormulaEvaluator;
    }

    public function test_it_evaluates_arithmetic_with_precedence_and_parentheses(): void
    {
        $this->assertSame(14.0, $this->eval->evaluate('2 + 3 * 4', []));
        $this->assertSame(20.0, $this->eval->evaluate('(2 + 3) * 4', []));
        $this->assertSame(2.5, $this->eval->evaluate('10 / 4', []));
    }

    public function test_it_resolves_metric_identifiers_with_dots(): void
    {
        $values = ['woocommerce.revenue' => 300000, 'woocommerce.orders' => 120, 'ga4.sessions' => 6000];

        // Average order value.
        $this->assertSame(2500.0, $this->eval->evaluate('woocommerce.revenue / woocommerce.orders', $values));
        // Conversion rate %.
        $this->assertSame(2.0, $this->eval->evaluate('woocommerce.orders / ga4.sessions * 100', $values));
    }

    public function test_it_rejects_an_unknown_metric(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval->evaluate('ga4.sessions + 1', []);
    }

    public function test_it_rejects_division_by_zero(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval->evaluate('10 / 0', []);
    }

    public function test_it_rejects_mismatched_parentheses(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval->evaluate('(2 + 3', []);
    }

    public function test_it_rejects_an_unexpected_character(): void
    {
        $this->expectException(FormulaException::class);
        $this->eval->evaluate('2 ; 3', []);
    }

    public function test_it_rejects_a_non_finite_overflow_result(): void
    {
        // huge * huge overflows to INF — must not be stored in a report.
        $this->expectException(FormulaException::class);
        $this->eval->evaluate('big * big', ['big' => 1e308]);
    }
}
