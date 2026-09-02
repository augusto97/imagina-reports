<?php

declare(strict_types=1);

namespace App\Connectors\Connect;

/**
 * What account discovery actually did, in words the client can act on.
 *
 * It used to answer with `?string`: a reason on failure, `null` otherwise — so every
 * success got the same sentence, "Listo. Si hay varias cuentas, elígela en el desplegable."
 * That sentence is wrong in the most common case: when the connection sees exactly ONE
 * property we pick it automatically and there is no dropdown, so the client is told to use
 * a control that isn't there and is never told which account was chosen. Indistinguishable,
 * from the outside, from "it detected nothing".
 */
final readonly class DiscoveryOutcome
{
    private function __construct(
        public ?string $error,
        public string $message,
    ) {}

    public static function failed(string $reason): self
    {
        return new self($reason, $reason);
    }

    /** Exactly one option: chosen for them — say which, so the row is verifiable. */
    public static function selected(string $label): self
    {
        return new self(null, "Cuenta detectada y seleccionada: {$label}. Pulsa «Probar» para confirmar.");
    }

    public static function choices(int $count, string $label): self
    {
        return new self(null, "Encontramos {$count} opciones. Elige {$label} en el desplegable de abajo.");
    }

    /** A source type that has nothing to discover (manual credentials). */
    public static function notApplicable(): self
    {
        return new self(null, 'Esta fuente no necesita detectar cuentas: se configura a mano.');
    }
}
