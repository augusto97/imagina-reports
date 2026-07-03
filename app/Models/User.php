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
            'role' => UserRole::class,
            'is_platform_admin' => 'boolean',
            'impersonating_agency_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
