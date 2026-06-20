<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who can see a report comment (CLAUDE.md §11). `internal` notes are team-only
 * (never exposed on the public report); `client` comments render in the report.
 */
enum CommentVisibility: string
{
    case Internal = 'internal';
    case Client = 'client';
}
