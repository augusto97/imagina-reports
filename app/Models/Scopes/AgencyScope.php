<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that constrains every query on an agency-scoped model to the
 * currently-bound tenant (CLAUDE.md §5). When no tenant is bound it is a no-op,
 * so CLI/seed/boot code can still operate across agencies deliberately.
 */
final class AgencyScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->hasAgency()) {
            $builder->where($model->getTable().'.agency_id', $tenant->id());
        }
    }
}
