<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a work-log task (CLAUDE.md §11.5). Only `Done` tasks appear in the
 * client-facing report; the others are internal planning/tracking states.
 */
enum WorkLogStatus: string
{
    case Done = 'done';
    case InProgress = 'in_progress';
    case Planned = 'planned';

    public function label(): string
    {
        return match ($this) {
            self::Done => 'Hecho',
            self::InProgress => 'En progreso',
            self::Planned => 'Planificado',
        };
    }
}
