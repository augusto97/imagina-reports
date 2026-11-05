<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who wrote a message in a report's conversation (Etapa 4): the client from the portal, the
 * agency replying from the panel, or the client asking the AI about the report.
 */
enum ReportMessageKind: string
{
    case Client = 'client';
    case Agency = 'agency';
    case Ai = 'ai';
}
