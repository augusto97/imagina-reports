<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduleCadence;
use App\Models\Concerns\BelongsToAgency;
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
        'next_run_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cadence' => ScheduleCadence::class,
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReportDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }
}
