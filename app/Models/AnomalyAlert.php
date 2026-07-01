<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnomalyType;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A persisted anomaly detection (CLAUDE.md §13) — the in-app alerts feed behind the
 * `anomaly.detected` webhook. Distinct from the `App\Reports\Anomaly` value object,
 * which is the transient detection result.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $site_id
 * @property int|null $report_id
 * @property AnomalyType $type
 * @property string $metric
 * @property float $current_value
 * @property float $previous_value
 * @property float $change_percent
 * @property Carbon|null $acknowledged_at
 */
class AnomalyAlert extends Model
{
    use BelongsToAgency;

    protected $table = 'ir_anomalies';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'site_id',
        'report_id',
        'type',
        'metric',
        'current_value',
        'previous_value',
        'change_percent',
        'acknowledged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AnomalyType::class,
            'current_value' => 'float',
            'previous_value' => 'float',
            'change_percent' => 'float',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
