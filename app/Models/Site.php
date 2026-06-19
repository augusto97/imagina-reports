<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client's website (CLAUDE.md §5). Owns the data sources and report definitions.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $client_id
 * @property string $name
 * @property string $url
 * @property string|null $hosting
 * @property string|null $support_plan
 * @property string $status
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_sites';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'client_id',
        'name',
        'url',
        'hosting',
        'support_plan',
        'status',
    ];

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<DataSource, $this>
     */
    public function dataSources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }
}
