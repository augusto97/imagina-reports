<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can open a shared report/dashboard via its public token (CLAUDE.md §10/Etapa D).
 */
enum ReportVisibility: string
{
    case Public = 'public';        // anyone with the link
    case Password = 'password';    // anyone with the link + the password
    case Private = 'private';      // not reachable via the public token
}
