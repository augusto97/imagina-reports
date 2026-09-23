<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who is calling and with which token. Every tool runs AS this user — same agency, same role,
 * same validation as the admin panel — never with more reach than the person has in the app.
 */
final readonly class McpContext
{
    public function __construct(
        public User $user,
        public PersonalAccessToken $token,
        public string $bearer,
        public int $agencyId,
    ) {}

    public function can(McpAbility $ability): bool
    {
        return $this->token->can($ability->value);
    }
}
