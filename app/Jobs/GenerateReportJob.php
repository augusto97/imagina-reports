<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Connectors\Period;
use App\Models\ReportDefinition;
use App\Models\Scopes\AgencyScope;
use App\Reports\ReportGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manual report generation (CLAUDE.md §8): the GENERATE stage as a queued job, so
 * the API responds immediately. Runs inside the definition's agency tenant context.
 */
final class GenerateReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $definitionId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {}

    public function handle(ReportGenerator $generator, TenantContext $tenant): void
    {
        $definition = ReportDefinition::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->find($this->definitionId);

        if ($definition === null) {
            return;
        }

        $period = new Period($this->periodStart, $this->periodEnd);

        $tenant->actingAs(
            $definition->agency_id,
            fn (): mixed => $generator->generate($definition, $period),
        );
    }
}
