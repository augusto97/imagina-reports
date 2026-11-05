<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Scopes\AgencyScope;
use App\Models\Site;
use App\Reports\AiReportBuilder;
use App\Reports\AiReportException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * «Generar con IA» runs here, in the background: a whole multi-page report can take the model a
 * few minutes (it reasons, then writes the plan), far longer than a web request may wait. The
 * editor polls the status (AiTemplateController::status) until it's done or failed.
 *
 * Modes: `template` (a whole report), `section` (blocks to add), `adjust` (revise the canvas).
 * The status lives in the cache for an hour, tied to the agency that asked.
 */
final class AiDesignJob implements ShouldQueue
{
    use Queueable;

    public const MODES = ['template', 'section', 'adjust'];

    /** The model streams for minutes on a big report; the redis retry_after (900 s) stays above. */
    public int $timeout = 840;

    public int $tries = 1;

    /**
     * @param  array<array-key, mixed>  $blocks  The canvas, for `adjust`.
     * @param  array<array-key, mixed>  $pages  The canvas' page names, for `adjust`.
     */
    public function __construct(
        public readonly string $jobId,
        public readonly int $agencyId,
        public readonly int $siteId,
        public readonly string $mode,
        public readonly string $prompt,
        public readonly array $blocks = [],
        public readonly array $pages = [],
    ) {}

    /**
     * Queue a run and return its id.
     *
     * @param  array<array-key, mixed>  $blocks
     * @param  array<array-key, mixed>  $pages
     */
    public static function start(int $agencyId, int $siteId, string $mode, string $prompt, array $blocks = [], array $pages = []): string
    {
        $id = (string) Str::uuid();
        self::put($id, ['agency_id' => $agencyId, 'mode' => $mode, 'status' => 'queued', 'queued_at' => now()->toIso8601String()]);
        self::dispatch($id, $agencyId, $siteId, $mode, $prompt, $blocks, $pages);

        return $id;
    }

    /**
     * Where a run stands, only for the agency that started it.
     *
     * @return array<string, mixed>|null
     */
    public static function status(string $id, int $agencyId): ?array
    {
        $state = self::read($id);

        return $state !== null && ($state['agency_id'] ?? null) === $agencyId ? $state : null;
    }

    public function handle(AiReportBuilder $builder, TenantContext $tenant): void
    {
        $this->update(['status' => 'running', 'started_at' => now()->toIso8601String()]);

        try {
            $result = $tenant->actingAs($this->agencyId, function () use ($builder): array {
                $site = Site::query()->withoutGlobalScope(AgencyScope::class)
                    ->where('agency_id', $this->agencyId)
                    ->findOrFail($this->siteId);

                return match ($this->mode) {
                    'section' => $builder->assembleSection($site, $this->prompt),
                    'adjust' => $builder->adjustTemplate($site, $this->prompt, $this->blocks, $this->pages),
                    default => $builder->assembleTemplate($site, $this->prompt === '' ? null : $this->prompt),
                };
            });
            $this->update(['status' => 'done', 'result' => $result]);
        } catch (AiReportException $exception) {
            $this->update(['status' => 'failed', 'message' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Log::warning('AI design job failed', ['job' => $this->jobId, 'mode' => $this->mode, 'error' => $exception->getMessage()]);
            $this->update(['status' => 'failed', 'message' => 'La IA no pudo terminar. Inténtalo otra vez en un momento.']);
        }
    }

    /** The worker gave up (e.g. it ran past its time): tell the editor instead of leaving it waiting. */
    public function failed(?Throwable $exception): void
    {
        $this->update(['status' => 'failed', 'message' => 'La IA tardó demasiado y se detuvo. Prueba a pedir un informe con menos páginas.']);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(array $changes): void
    {
        $state = self::read($this->jobId) ?? ['agency_id' => $this->agencyId, 'mode' => $this->mode];
        self::put($this->jobId, [...$state, ...$changes]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read(string $id): ?array
    {
        $state = Cache::get(self::key($id));

        return is_array($state) ? array_filter($state, 'is_string', ARRAY_FILTER_USE_KEY) : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function put(string $id, array $state): void
    {
        Cache::put(self::key($id), [...$state, 'updated_at' => now()->toIso8601String()], now()->addHour());
    }

    private static function key(string $id): string
    {
        return 'ai-design:'.$id;
    }
}
