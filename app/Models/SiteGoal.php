<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A goal the agency agreed with the client for one site (Etapa 4), measured against one metric
 * every period: reach a number (at_least) or stay under it (at_most).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $site_id
 * @property string $label
 * @property string $source
 * @property string $metric
 * @property string $target
 * @property string $direction
 * @property string|null $format
 * @property int $position
 */
class SiteGoal extends Model
{
    use BelongsToAgency;

    public const AT_LEAST = 'at_least';

    public const AT_MOST = 'at_most';

    public const FORMATS = ['number', 'percent', 'currency', 'duration'];

    protected $table = 'ir_site_goals';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['direction' => self::AT_LEAST, 'position' => 0];

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'site_id', 'label', 'source', 'metric', 'target', 'direction', 'format', 'position'];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'label' => $this->label,
            'source' => $this->source,
            'metric' => $this->metric,
            'target' => (float) $this->target,
            'direction' => $this->direction,
            'format' => $this->format,
            'position' => $this->position,
        ];
    }
}
