<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AppReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A published application release the self-updater can install (CLAUDE.md §5/§12).
 * System-level (no agency scope).
 *
 * @property int $id
 * @property string $version
 * @property string $channel
 * @property string $bundle_url
 * @property string|null $checksum
 * @property Carbon $released_at
 */
class AppRelease extends Model
{
    /** @use HasFactory<AppReleaseFactory> */
    use HasFactory;

    protected $table = 'ir_app_releases';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'version',
        'channel',
        'bundle_url',
        'checksum',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }
}
