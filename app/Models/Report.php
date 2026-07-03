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
 * @property list<string>|null $hidden_metrics
 * @property bool $has_advisory
 * @property string|null $advisory
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
        'hidden_metrics',
        'has_advisory',
        'advisory',
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
            'hidden_metrics' => 'array',
            'has_advisory' => 'boolean',
            'status' => ReportStatus::class,
        ];
    }

    /**
     * Keep the denormalized list-summary columns in sync with resolved_blocks (PERF-3): any
     * save that changes the snapshot — generation, advisory edit, regenerate — recomputes them,
     * so the reports LIST can read light columns and never decode the heavy JSON.
     */
    protected static function booted(): void
    {
        static::saving(static function (Report $report): void {
            if ($report->isDirty('resolved_blocks')) {
                $report->forceFill($report->deriveListSummary());
            }
        });
    }

    /**
     * Derive the reports-list fields from resolved_blocks: the metrics whose blocks were hidden
     * for lack of data (diagnostics), whether an advisory block exists, and its current text.
     *
     * @return array{hidden_metrics: list<string>, has_advisory: bool, advisory: string|null}
     */
    public function deriveListSummary(): array
    {
        $resolved = $this->resolved_blocks;

        $diagnostics = $resolved['diagnostics'] ?? [];
        $hiddenMetrics = [];

        if (is_array($diagnostics)) {
            foreach ($diagnostics as $diagnostic) {
                $source = is_array($diagnostic) ? ($diagnostic['source'] ?? null) : null;
                $metric = is_array($diagnostic) ? ($diagnostic['metric'] ?? null) : null;

                if (is_string($source) && is_string($metric)) {
                    $hiddenMetrics[] = "{$source}.{$metric}";
                }
            }
        }

        $blocks = $resolved['blocks'] ?? [];
        $data = $resolved['data'] ?? [];
        $hasAdvisory = false;
        $advisory = null;

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'advisory') {
                    $hasAdvisory = true;
                    $id = $block['id'] ?? null;
                    $value = is_string($id) && is_array($data) ? ($data[$id] ?? null) : null;
                    if (is_string($value)) {
                        $advisory = $value;
                    }
                }
            }
        }

        return ['hidden_metrics' => $hiddenMetrics, 'has_advisory' => $hasAdvisory, 'advisory' => $advisory];
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
     * @return HasMany<ReportDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ReportDelivery::class);
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
