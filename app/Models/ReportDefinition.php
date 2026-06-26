<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportVisibility;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ReportDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's report configuration (CLAUDE.md §5). Resolves to a report via the
 * ReportGenerator using its blocks (or its template's, or the default).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $site_id
 * @property string $name
 * @property int|null $template_id
 * @property array<int, array<string, mixed>>|null $blocks
 * @property array<int, string>|null $requested_metrics
 * @property array<int, array<string, mixed>>|null $calculated_metrics
 * @property array<string, mixed>|null $theme
 * @property array<array-key, list<array<string, mixed>>>|null $filters
 * @property ReportVisibility $visibility
 * @property string|null $password_hash
 * @property array<int, string>|null $embed_domains
 * @property string $locale
 * @property array<string, mixed>|null $schedule
 * @property array<int, string>|null $recipients
 */
class ReportDefinition extends Model
{
    /** @use HasFactory<ReportDefinitionFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_report_definitions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'site_id',
        'name',
        'template_id',
        'blocks',
        'requested_metrics',
        'calculated_metrics',
        'theme',
        'filters',
        'visibility',
        'embed_domains',
        'locale',
        'schedule',
        'recipients',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'requested_metrics' => 'array',
            'calculated_metrics' => 'array',
            'theme' => 'array',
            'filters' => 'array',
            'visibility' => ReportVisibility::class,
            'embed_domains' => 'array',
            'schedule' => 'array',
            'recipients' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }
}
