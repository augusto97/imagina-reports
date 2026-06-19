<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every agency-scoped domain model. It adds the AgencyScope global
 * scope and auto-stamps agency_id from the bound tenant on create, so tenant
 * isolation is enforced by default and impossible to forget (CLAUDE.md §5, §14).
 *
 * @phpstan-require-extends Model
 */
trait BelongsToAgency
{
    public static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('agency_id') !== null) {
                return;
            }

            $tenant = app(TenantContext::class);

            if ($tenant->hasAgency()) {
                $model->setAttribute('agency_id', $tenant->id());
            }
        });
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
