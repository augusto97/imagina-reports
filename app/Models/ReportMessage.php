<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportMessageKind;
use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message in a report's conversation (Etapa 4): a client question from the portal, an
 * agency reply, or a question the client asked the AI (with its answer).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $report_id
 * @property ReportMessageKind $kind
 * @property int|null $user_id
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string $body
 * @property string|null $answer
 * @property Carbon|null $handled_at
 * @property Carbon $created_at
 */
class ReportMessage extends Model
{
    use BelongsToAgency;

    protected $table = 'ir_report_messages';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_id',
        'kind',
        'user_id',
        'author_name',
        'author_email',
        'body',
        'answer',
        'handled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ReportMessageKind::class,
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A client question nobody at the agency has answered or set aside yet. */
    public function isPending(): bool
    {
        return $this->kind === ReportMessageKind::Client && $this->handled_at === null;
    }

    /**
     * The shape both the portal and the panel read. The portal never gets the client's email
     * (anyone with the link could read it); the panel does, to know who to answer.
     *
     * @return array<string, mixed>
     */
    public function present(bool $forAgency = false): array
    {
        $author = match ($this->kind) {
            ReportMessageKind::Agency => $this->user !== null ? $this->user->name : null,
            default => $this->author_name,
        };

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'author' => $author,
            'body' => $this->body,
            'answer' => $this->answer,
            'created_at' => $this->created_at->toIso8601String(),
            ...($forAgency ? ['author_email' => $this->author_email, 'handled_at' => $this->handled_at?->toIso8601String()] : []),
        ];
    }
}
