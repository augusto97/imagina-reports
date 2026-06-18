<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable block-based report template (CLAUDE.md §5/§10.2).
 *
 * @property int $id
 * @property int $agency_id
 * @property string $name
 * @property array<int, array<string, mixed>> $blocks
 * @property bool $is_default
 * @property string $locale
 */
class ReportTemplate extends Model
{
    /** @use HasFactory<ReportTemplateFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_report_templates';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'name',
        'blocks',
        'is_default',
        'locale',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
