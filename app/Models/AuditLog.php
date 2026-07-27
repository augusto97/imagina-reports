<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded sensitive action (see AuditLogger). Append-only by convention: nothing in the
 * app updates or deletes rows, so the trail can be trusted.
 *
 * @property int $id
 * @property int $agency_id
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property string|null $actor_email
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $summary
 * @property array<string, mixed>|null $meta
 * @property string|null $ip
 */
class AuditLog extends Model
{
    use BelongsToAgency;

    protected $table = 'ir_audit_logs';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id', 'actor_id', 'actor_name', 'actor_email', 'action',
        'subject_type', 'subject_id', 'summary', 'meta', 'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
