<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentVisibility;
use App\Models\Concerns\BelongsToAgency;
use Database\Factories\ReportCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment/annotation on a report (CLAUDE.md §11): an internal team note or a
 * client-visible comment.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $report_id
 * @property int|null $author_user_id
 * @property string $body
 * @property CommentVisibility $visibility
 * @property Carbon $created_at
 */
class ReportComment extends Model
{
    /** @use HasFactory<ReportCommentFactory> */
    use BelongsToAgency, HasFactory;

    protected $table = 'ir_report_comments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'agency_id',
        'report_id',
        'author_user_id',
        'body',
        'visibility',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => CommentVisibility::class,
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
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
