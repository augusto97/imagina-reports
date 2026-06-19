<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A delivery attempt of a report to a recipient (CLAUDE.md §5).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $report_id
 * @property DeliveryChannel $channel
 * @property string $recipient
 * @property DeliveryStatus $status
 * @property Carbon|null $sent_at
 * @property string|null $error
 */
class ReportDelivery extends Model
{
    use BelongsToAgency;

    protected $table = 'ir_report_deliveries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_id',
        'channel',
        'recipient',
        'status',
        'sent_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => DeliveryChannel::class,
            'status' => DeliveryStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
