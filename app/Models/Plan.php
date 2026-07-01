<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan (SaaS Fase 1). Global (NOT tenant-scoped): plans are owned by the
 * platform, assigned to agencies. Limits are null = unlimited; features is a flag map.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $is_active
 * @property int $sort
 * @property int|null $max_sites
 * @property int|null $max_data_sources
 * @property int|null $max_clients
 * @property int|null $max_users
 * @property int|null $max_reports_per_month
 * @property list<string>|null $allowed_connectors
 * @property array<string, bool>|null $features
 * @property float|null $monthly_price
 * @property string $currency
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $table = 'ir_plans';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'sort',
        'max_sites',
        'max_data_sources',
        'max_clients',
        'max_users',
        'max_reports_per_month',
        'allowed_connectors',
        'features',
        'monthly_price',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'max_sites' => 'integer',
            'max_data_sources' => 'integer',
            'max_clients' => 'integer',
            'max_users' => 'integer',
            'max_reports_per_month' => 'integer',
            'allowed_connectors' => 'array',
            'features' => 'array',
            'monthly_price' => 'decimal:2',
        ];
    }

    public function hasFeature(string $key): bool
    {
        return (bool) ($this->features[$key] ?? false);
    }

    /**
     * @return HasMany<Agency, $this>
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }
}
