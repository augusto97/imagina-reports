<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Abort with 403 unless the caller is a privileged agency user (owner/admin), or a
     * platform admin. Gates administrative, financial and destructive actions a
     * collaborator must not perform (CLAUDE.md §5).
     */
    protected function authorizePrivileged(Request $request): void
    {
        $user = $request->user();

        $ok = $user instanceof User && ($user->is_platform_admin || $user->role->isPrivileged());

        abort_unless($ok, 403, 'Solo el propietario o un administrador de la agencia pueden hacer esto.');
    }
}
