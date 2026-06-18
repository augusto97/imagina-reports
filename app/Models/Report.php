<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A generated report (CLAUDE.md §5): a frozen snapshot of resolved blocks for a
 * period. `resolved_blocks` is `{blocks: [...], data: {blockId: value}}`, the exact
 * input the shared BlockList renders for the portal and the PDF.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $report_definition_id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, mixed> $resolved_blocks
 * @property int|null $health_score
 * @property string|null $executive_summary
 * @property string|null $pdf_path
 * @property string $public_token
 * @property ReportStatus $status
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_definition_id',
        'period_start',
        'period_end',
        'resolved_blocks',
        'health_score',
        'executive_summary',
        'pdf_path',
        'public_token',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'resolved_blocks' => 'array',
            'health_score' => 'integer',
            'status' => ReportStatus::class,
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
