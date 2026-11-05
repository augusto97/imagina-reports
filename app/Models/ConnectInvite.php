<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A link the agency sends to a client so they connect their own accounts to one site
 * (no login in the app). Scoped to a site and to the source types the agency chose,
 * expires, and can be revoked. Looked up by the SHA-256 of the token, never the token.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $site_id
 * @property string $token
 * @property string $token_hash
 * @property list<string> $types
 * @property Carbon $expires_at
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
class ConnectInvite extends Model
{
    use BelongsToAgency;

    protected $table = 'ir_connect_invites';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'site_id',
        'token',
        'token_hash',
        'types',
        'expires_at',
        'created_by',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token', 'token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'types' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * A fresh link for a site and the sources it may connect. Shared by «Pedir acceso al
     * cliente» and «Pedir reconexión» (Etapa 5), so both make the same kind of link.
     *
     * @param  list<string>  $types
     */
    public static function issue(Site $site, array $types, int $days, ?int $createdBy): self
    {
        $token = self::newToken();

        return self::query()->create([
            'agency_id' => $site->agency_id,
            'site_id' => $site->id,
            'token' => $token,
            'token_hash' => self::hashToken($token),
            'types' => $types,
            'expires_at' => now()->addDays($days),
            'created_by' => $createdBy,
        ]);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The live invite for a token, across tenants (the public page has no tenant), or null
     * when it doesn't exist, expired or was revoked.
     */
    public static function findActive(string $token): ?self
    {
        if (strlen($token) < 20 || strlen($token) > 128) {
            return null;
        }

        $invite = self::query()->withoutGlobalScopes()->where('token_hash', self::hashToken($token))->first();

        return $invite !== null && $invite->isActive() ? $invite : null;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function allows(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function url(): string
    {
        $appUrl = config('app.url');

        return rtrim(is_string($appUrl) ? $appUrl : '', '/').'/connect/'.$this->token;
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
