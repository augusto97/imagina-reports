<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

use RuntimeException;

/**
 * Account discovery could not complete, WITH a reason worth showing the client.
 *
 * Every connector used to answer a failed provider call with a bare `null`, so the panel
 * could only ever say "no pudimos consultar a qué cuentas tiene acceso esta conexión" —
 * the same sentence whether the token had expired, a scope was missing, the Meta app was
 * still in development mode, or a quota had run out. That made the one thing the operator
 * needs (what the provider actually said) unreachable without server logs.
 */
final class DiscoveryFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
