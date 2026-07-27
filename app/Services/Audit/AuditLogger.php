<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Records sensitive actions to the audit trail (CLAUDE.md §5 — who did what, when, from
 * where). Deliberately fail-safe: a logging problem must never break the action the user
 * asked for, so everything is wrapped and only reported to the application log.
 *
 * Never pass secrets in $meta — store what changed, not the value (e.g. 'field' => 'password').
 */
final class AuditLogger
{
    // Action names are stable identifiers (used for filtering); the UI maps them to labels.
    public const TEAM_CREATED = 'team.created';

    public const TEAM_UPDATED = 'team.updated';

    public const TEAM_DELETED = 'team.deleted';

    public const SHARING_UPDATED = 'sharing.updated';

    public const SHARING_TOKEN_ROTATED = 'sharing.token_rotated';

    public const DATA_SOURCE_CREATED = 'data_source.created';

    public const DATA_SOURCE_UPDATED = 'data_source.updated';

    public const DATA_SOURCE_DELETED = 'data_source.deleted';

    public const REPORT_DELETED = 'report.deleted';

    public const REPORT_SENT = 'report.sent';

    public const ACCOUNT_PASSWORD_CHANGED = 'account.password_changed';

    public const ACCOUNT_EMAIL_CHANGE_REQUESTED = 'account.email_change_requested';

    public const ACCOUNT_EMAIL_CHANGED = 'account.email_changed';

    public const ACCOUNT_2FA_ENABLED = 'account.two_factor_enabled';

    public const ACCOUNT_2FA_DISABLED = 'account.two_factor_disabled';

    public const AGENCY_DELETED = 'agency.deleted';

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function record(string $action, ?Model $subject = null, ?string $summary = null, array $meta = [], ?User $actor = null, ?int $agencyId = null): void
    {
        try {
            $actor ??= Auth::user() instanceof User ? Auth::user() : null;
            $agencyId ??= $actor?->agency_id;

            // Platform-admin actions outside any agency have nothing to scope to.
            if ($agencyId === null) {
                return;
            }

            AuditLog::query()->create([
                'agency_id' => $agencyId,
                'actor_id' => $actor?->getKey(),
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'action' => $action,
                'subject_type' => $subject !== null ? class_basename($subject) : null,
                'subject_id' => is_numeric($subject?->getKey()) ? (int) $subject->getKey() : null,
                'summary' => $summary,
                'meta' => $meta === [] ? null : $meta,
                'ip' => Request::ip(),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the action being audited.
            Log::warning('Failed to write an audit log entry.', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
