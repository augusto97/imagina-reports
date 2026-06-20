<?php

declare(strict_types=1);

namespace App\Reports\Calc;

use RuntimeException;

/**
 * Raised when a calculated-metric formula is malformed, references an unknown
 * metric, or divides by zero. Callers skip the metric (graceful) rather than fail.
 */
final class FormulaException extends RuntimeException {}
