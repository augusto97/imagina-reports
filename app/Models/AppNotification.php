<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One notice in a team member's bell (see SourceNotifier): what happened, on which site, and
 * where to go to look at it. Each person reads and clears their own.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $user_id
 * @property string $kind
 * @property string $title
 * @property string|null $body
 * @property int|null $site_id
 * @property int|null $data_source_id
 * @property string|null $link
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class AppNotification extends Model
{
    use BelongsToAgency;

    /** How many a person keeps: older ones are dropped as new ones arrive. */
    public const KEEP = 200;

    protected $table = 'ir_notifications';

    /**
     * @var list<string>
     */
    protected $fillable = ['agency_id', 'user_id', 'kind', 'title', 'body', 'site_id', 'data_source_id', 'link', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /**
     * @return array{id: int, kind: string, title: string, body: string|null, site_id: int|null, data_source_id: int|null, link: string|null, read: bool, created_at: string|null}
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'site_id' => $this->site_id,
            'data_source_id' => $this->data_source_id,
            'link' => $this->link,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
