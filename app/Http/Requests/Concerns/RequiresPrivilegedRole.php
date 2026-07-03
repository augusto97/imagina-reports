<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Restricts a FormRequest to privileged agency roles (owner/admin). Collaborators are the
 * low-trust role (CLAUDE.md §5), so administrative/sensitive actions — agency settings,
 * connector credentials, billing, sharing, destructive deletes — must not be theirs.
 */
trait RequiresPrivilegedRole
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->role->isPrivileged();
    }
}
