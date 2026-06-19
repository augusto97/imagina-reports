<?php

declare(strict_types=1);

namespace App\Models;

use App\Connectors\MetricSetStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\MetricSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A normalized snapshot of one data source's metrics for one period (CLAUDE.md §5).
 * `payload` is the metric bag (MetricSet::toArray()); reports read these, never APIs.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $data_source_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, mixed> $payload
 * @property MetricSetStatus $status
 * @property Carbon $captured_at
 */
class MetricSnapshot extends Model
{
    /** @use HasFactory<MetricSnapshotFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_metric_snapshots';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'data_source_id',
        'period_start',
        'period_end',
        'payload',
        'status',
        'captured_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'payload' => 'array',
            'status' => MetricSetStatus::class,
            'captured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
