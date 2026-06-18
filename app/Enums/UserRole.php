<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three base roles for an agency's users (CLAUDE.md §5). Stored as a string
 * enum column on ir_users; spatie/permission is reserved for finer-grained needs.
 */
enum UserRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Collaborator = 'collaborator';

    /**
     * Whether this role may manage the agency and its users.
     */
    public function isPrivileged(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }
}
