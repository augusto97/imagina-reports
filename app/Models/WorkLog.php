<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WorkLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A "what we did this month" entry (CLAUDE.md §5/§11.5).
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $report_id
 * @property int $site_id
 * @property Carbon $performed_at
 * @property string $description
 * @property string|null $screenshot_path
 */
class WorkLog extends Model
{
    /** @use HasFactory<WorkLogFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_report_work_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_id',
        'site_id',
        'performed_at',
        'description',
        'screenshot_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
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
