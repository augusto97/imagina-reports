<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or promotes) the platform super-admin (SaaS Fase 1). A platform admin has no
 * home agency and manages every agency from the platform panel.
 *
 * Usage: php artisan platform:create-admin "Name" email@example.com [password]
 */
final class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'platform:create-admin {name} {email} {password?}';

    protected $description = 'Create or promote a platform super-admin (manages all agencies).';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) ($this->argument('password') ?? bin2hex(random_bytes(6)));

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) $this->argument('name'),
                'password' => Hash::make($password),
                'agency_id' => null,
                'role' => UserRole::Owner,
                'is_platform_admin' => true,
            ],
        );

        $this->info("Platform admin ready: {$user->email}");
        if ($this->argument('password') === null) {
            $this->line("Generated password: {$password}");
        }

        return self::SUCCESS;
    }
}
