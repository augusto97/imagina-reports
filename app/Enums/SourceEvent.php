<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What can happen to a client's connection that the team wants to hear about. Each team
 * member chooses, per event, whether it shows in their bell and whether it also arrives by
 * email (NotificationPrefs).
 */
enum SourceEvent: string
{
    case Connected = 'source_connected';
    case Failed = 'source_failed';
    case Recovered = 'source_recovered';
    case Removed = 'source_removed';

    /** Name in the settings list. */
    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Se conecta una fuente',
            self::Failed => 'Una fuente deja de funcionar',
            self::Recovered => 'Una fuente vuelve a funcionar',
            self::Removed => 'Alguien quita una fuente',
        };
    }

    /** One line under the name, so nobody has to guess what triggers it. */
    public function help(): string
    {
        return match ($this) {
            self::Connected => 'Cuando alguien del equipo o el cliente (desde su enlace de conexión) conecta o vuelve a conectar una cuenta.',
            self::Failed => 'Cuando una conexión deja de traer datos (permiso vencido, clave revocada…). Una sola vez por caída.',
            self::Recovered => 'Cuando una conexión que había fallado vuelve a traer datos.',
            self::Removed => 'Cuando alguien del equipo borra una fuente de un sitio.',
        };
    }

    /** The webhook / Slack event name. */
    public function webhook(): string
    {
        return match ($this) {
            self::Connected => 'source.connected',
            self::Failed => 'source.failed',
            self::Recovered => 'source.recovered',
            self::Removed => 'source.removed',
        };
    }
}
