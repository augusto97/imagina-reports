<?php

declare(strict_types=1);

namespace App\Services\Billing;

use RuntimeException;

/** A billing/payment-provider failure surfaced to the caller (SaaS Fase 2). */
final class BillingException extends RuntimeException {}
