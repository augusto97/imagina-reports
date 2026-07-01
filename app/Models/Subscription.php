<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An agency's paid subscription (SaaS Fase 2). Not tenant-scoped by AgencyScope — billing
 * is resolved by explicit agency_id (agency panel) or by provider webhook (no tenant).
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $plan_id
 * @property string $provider
 * @property string|null $external_id
 * @property SubscriptionStatus $status
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_until
 * @property array<string, mixed>|null $meta
 */
class Subscription extends Model
{
    protected $table = 'ir_subscriptions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'plan_id',
        'provider',
        'external_id',
        'status',
        'current_period_end',
        'grace_until',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'current_period_end' => 'datetime',
            'grace_until' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
