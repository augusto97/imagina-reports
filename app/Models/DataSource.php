<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataSourceStatus;
use App\Enums\DataSourceType;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A configured connector instance for a site (CLAUDE.md §5/§9). Agency-scoped.
 * `credentials` use an encrypted cast — never log them (§6).
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $site_id
 * @property DataSourceType $type
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $config
 * @property string|null $push_token
 * @property string|null $push_token_hash
 * @property DataSourceStatus $status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_data_sources';

    /**
     * In-memory default so a freshly-created source has a status before reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DataSourceStatus::Pending->value,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'site_id',
        'type',
        'credentials',
        'config',
        'push_token',
        'push_token_hash',
        'status',
        'last_synced_at',
        'last_error',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'credentials',
        'push_token_hash',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataSourceType::class,
            'status' => DataSourceStatus::class,
            'credentials' => 'encrypted:array',
            // Encrypted at rest (audit SEC). Lookups go through push_token_hash, never this.
            'push_token' => 'encrypted',
            'config' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Assign a fresh push token: store it encrypted (for display) and its deterministic hash
     * (the ingest lookup key). Always set together so the two never drift.
     */
    public function assignPushToken(string $token): void
    {
        $this->forceFill([
            'push_token' => $token,
            'push_token_hash' => self::hashPushToken($token),
        ])->save();
    }

    /** The deterministic lookup hash for a push token (what the ingest endpoint matches on). */
    public static function hashPushToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
