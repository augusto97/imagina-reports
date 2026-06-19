<?php

declare(strict_types=1);

namespace App\Connectors\Exceptions;

use RuntimeException;

final class UnknownConnectorException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("No connector is registered for key [{$key}].");
    }
}
