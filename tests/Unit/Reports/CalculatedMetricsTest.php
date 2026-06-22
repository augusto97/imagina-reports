<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Reports\Calc\CalculatedMetrics;
use App\Reports\Calc\FormulaEvaluator;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CalculatedMetricsTest extends TestCase
{
    private function metrics(): CalculatedMetrics
    {
        return new CalculatedMetrics(new FormulaEvaluator);
    }

    public function test_it_computes_valid_formulas_over_the_bag(): void
    {
        $bags = ['woocommerce' => ['woocommerce.revenue' => 300000, 'woocommerce.orders' => 120]];

        $result = $this->metrics()->compute(
            [['key' => 'aov', 'formula' => 'woocommerce.revenue / woocommerce.orders']],
            $bags,
        );

        $this->assertSame(2500.0, $result['calc.aov']);
    }

    public function test_it_logs_and_skips_a_formula_that_cannot_be_computed(): void
    {
        Log::spy();

        // 'ga4.sessions' is not in the bag → Unknown metric → skipped (block hides) + logged.
        $result = $this->metrics()->compute(
            [
                ['key' => 'good', 'formula' => 'woocommerce.revenue / 2'],
                ['key' => 'bad', 'formula' => 'ga4.sessions / woocommerce.orders'],
            ],
            ['woocommerce' => ['woocommerce.revenue' => 1000]],
        );

        $this->assertArrayHasKey('calc.good', $result);
        $this->assertArrayNotHasKey('calc.bad', $result);

        Log::shouldHaveReceived('warning')->once();
    }
}
