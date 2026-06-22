<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\WorkLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A "what we did this month" entry (CLAUDE.md §5/§11.5).
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $report_id
 * @property int $site_id
 * @property Carbon $performed_at
 * @property string $description
 * @property int|null $minutes
 * @property string|null $category
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
        'minutes',
        'category',
        'screenshot_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Public URL of the proof-of-work screenshot, or null when none was attached.
     */
    public function screenshotUrl(): ?string
    {
        return $this->screenshot_path !== null ? Storage::disk('public')->url($this->screenshot_path) : null;
    }
}
