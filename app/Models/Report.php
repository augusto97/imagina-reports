<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * @return HasMany<WorkLog, $this>
     */
    public function workLogs(): HasMany
    {
        return $this->hasMany(WorkLog::class)->orderBy('performed_at');
    }

    /**
     * @return HasMany<ReportComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ReportComment::class)->latest();
    }

    /**
     * Server-only token that lets the PDF renderer bypass the portal's visibility/password
     * gate (CLAUDE.md §10.7/Etapa D). Derived from the public token + the app key, so it
     * can't be forged without the server secret; only the PDF service ever produces it.
     */
    public function printToken(): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', 'print:'.$this->public_token, is_string($key) ? $key : '');
    }
}
