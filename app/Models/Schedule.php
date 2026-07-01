<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduleCadence;
use App\Models\Concerns\BelongsToAgency;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring generation schedule for a report definition (CLAUDE.md §5).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $report_definition_id
 * @property ScheduleCadence $cadence
 * @property int|null $send_day
 * @property Carbon $next_run_at
 */
class Schedule extends Model
{
    /** @use HasFactory<ScheduleFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_schedules';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_definition_id',
        'cadence',
        'send_day',
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cadence' => ScheduleCadence::class,
            'send_day' => 'integer',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * The next time this schedule should fire, strictly after `$now`. Monthly honors the
     * designated `send_day` (1–28, default 1) so the agency picks WHICH day the report
     * goes out; weekly uses the connector default (start of next week).
     */
    public function nextRunAfter(CarbonImmutable $now): CarbonImmutable
    {
        if ($this->cadence !== ScheduleCadence::Monthly) {
            return $this->cadence->nextRun($now);
        }

        $day = min(28, max(1, $this->send_day ?? 1));
        $thisMonth = $now->startOfMonth()->addDays($day - 1)->startOfDay();

        return $thisMonth->greaterThan($now)
            ? $thisMonth
            : $now->addMonthNoOverflow()->startOfMonth()->addDays($day - 1)->startOfDay();
    }

    /**
     * @return BelongsTo<ReportDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
