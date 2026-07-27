<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int|null $agency_id
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_platform_admin
 * @property int|null $impersonating_agency_id
 * @property string|null $two_factor_secret decrypted by the cast
 * @property list<string>|null $two_factor_recovery_codes decrypted + decoded by the cast
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property string|null $pending_email
 * @property string|null $pending_email_token
 * @property \Illuminate\Support\Carbon|null $pending_email_sent_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'ir_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    // Deliberately excludes agency_id, is_platform_admin and impersonating_agency_id
    // (audit SEC hardening): those are privilege/tenant boundaries and are only ever set from
    // server-derived values via forceFill (team/platform controllers), never mass-assigned
    // from request input.
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'pending_email_token',
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // 2FA material is encrypted at rest: a DB dump alone can't mint valid codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'pending_email_sent_at' => 'datetime',
            'role' => UserRole::class,
            'is_platform_admin' => 'boolean',
            'impersonating_agency_id' => 'integer',
        ];
    }

    /** Whether the user finished enrolling in two-factor auth (a confirmed code). */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && is_string($this->two_factor_secret) && $this->two_factor_secret !== '';
    }

    /**
     * Consume a one-time recovery code. Returns false when it doesn't match; on a match the
     * code is burned (removed from the stored list) so it can never be reused.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $codes = $this->two_factor_recovery_codes ?? [];
        $needle = strtoupper(trim($code));

        $remaining = [];
        $matched = false;
        foreach ($codes as $stored) {
            // Only the first match is burned, so duplicates in the list stay usable.
            if (! $matched && hash_equals(strtoupper($stored), $needle)) {
                $matched = true;

                continue;
            }
            $remaining[] = $stored;
        }

        if ($matched) {
            $this->forceFill(['two_factor_recovery_codes' => $remaining])->save();
        }

        return $matched;
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
