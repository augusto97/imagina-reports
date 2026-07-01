<?php

declare(strict_types=1);

namespace App\Services\Update;

use App\Models\AppRelease;
use Illuminate\Support\Facades\Cache;

/**
 * Orchestrates the self-update flow (CLAUDE.md §12.3/§12.4). All destructive work
 * is delegated to a Deployer; this class is pure logic and fully testable.
 *
 * The update runs out-of-band on the queue (RunUpdateJob), so its progress/result is
 * persisted to the (Redis, shared-across-releases) cache as a "last run" record — the
 * status endpoint surfaces it so the admin can see running/success/failed instead of a
 * silent "queued".
 *
 * @phpstan-type RunState array{status: string, version: string|null, message: string, at: string|null}
 */
final readonly class UpdateManager
{
    private const STATE_KEY = 'ir:update:last_run';

    private const WORKER_KEY = 'ir:update:worker_version';

    private const LOCK_KEY = 'ir:update:lock:';

    private const DONE_KEY = 'ir:update:done:';

    public function __construct(private Deployer $deployer) {}

    /**
     * Record the version the QUEUE WORKER is running (called by a queued job). The web
     * layer and the worker are separate processes; after an update the worker only picks
     * up new code once Horizon is restarted, so surfacing its version reveals a stale
     * worker — the cause of "the web is updated but generated reports use the old logic".
     */
    public function recordWorkerVersion(): void
    {
        Cache::forever(self::WORKER_KEY, ['version' => $this->currentVersion(), 'at' => now()->toIso8601String()]);
    }

    /**
     * @return array{version: string|null, at: string|null}
     */
    public function workerState(): array
    {
        $state = Cache::get(self::WORKER_KEY);

        if (is_array($state) && array_key_exists('version', $state)) {
            $version = is_string($state['version']) ? $state['version'] : null;
            $at = isset($state['at']) && is_string($state['at']) ? $state['at'] : null;

            return ['version' => $version, 'at' => $at];
        }

        return ['version' => null, 'at' => null];
    }

    public function currentVersion(): string
    {
        // Each release bundle ships a VERSION file (written by CI), so the running
        // version is accurate per deploy regardless of the static APP_VERSION env.
        $file = base_path('VERSION');

        if (is_file($file)) {
            $version = trim((string) file_get_contents($file));

            if ($version !== '') {
                return $version;
            }
        }

        $version = config('app.version');

        return is_string($version) ? $version : '0.0.0';
    }

    public function availableRelease(): ?AppRelease
    {
        $channel = config('updater.channel');

        return AppRelease::query()
            ->where('channel', is_string($channel) ? $channel : 'stable')
            ->orderByDesc('released_at')
            ->first();
    }

    /**
     * @return array{current: string, available: string|null, update_available: bool, worker_version: string|null, worker_checked_at: string|null, last_run: RunState}
     */
    public function status(): array
    {
        $current = $this->currentVersion();
        $release = $this->availableRelease();
        $worker = $this->workerState();

        return [
            'current' => $current,
            'available' => $release?->version,
            'update_available' => $release !== null && version_compare($release->version, $current, '>'),
            'worker_version' => $worker['version'],
            'worker_checked_at' => $worker['at'],
            'last_run' => $this->lastRun(),
        ];
    }

    /**
     * @return RunState
     */
    public function lastRun(): array
    {
        $state = Cache::get(self::STATE_KEY);

        if (! is_array($state) || ! isset($state['status'], $state['message'])) {
            return ['status' => 'idle', 'version' => null, 'message' => '', 'at' => null];
        }

        /** @var RunState $state */
        return $this->reconcile($state);
    }

    /**
     * Self-heal a stuck in-progress run. The deploy flips the `current` symlink before
     * restarting Horizon, and that restart kills the very worker running the update job
     * (see SymlinkDeployer) — so an interrupted run can leave the state at "running" even
     * though the install finished. Resolve it from observable facts:
     *  - the web already serves the target version  → success (the flip happened);
     *  - it has been stuck well past any real deploy → failed (so the UI never hangs).
     *
     * @param  RunState  $state
     * @return RunState
     */
    private function reconcile(array $state): array
    {
        if ($state['status'] !== 'running' && $state['status'] !== 'queued') {
            return $state;
        }

        $version = $state['version'];

        if (is_string($version) && version_compare($this->currentVersion(), $version, '>=')) {
            return ['status' => 'success', 'version' => $version, 'message' => "Actualizado a la versión {$version}.", 'at' => $state['at']];
        }

        $at = is_string($state['at']) ? strtotime($state['at']) : false;

        if ($at !== false && (now()->getTimestamp() - $at) > 1200) {
            return ['status' => 'failed', 'version' => $version, 'message' => 'La actualización no finalizó a tiempo. Reinicia los trabajadores (Horizon) y reintenta, o usa Rollback.', 'at' => $state['at']];
        }

        return $state;
    }

    /**
     * Record that an update has been enqueued (the UI shows "en cola" immediately).
     */
    public function markQueued(): void
    {
        $release = $this->availableRelease();
        $this->setState('queued', $release?->version, 'Actualización en cola.');
    }

    /**
     * Install the latest available release, auto-rolling back on a failed health check.
     *
     * Re-entrancy matters here: the queue's `retry_after` re-reserves a long-running job,
     * so this method can be invoked twice for the same release. An atomic lock serializes
     * the real deploy, and a per-version "done" marker makes a duplicate attempt a no-op
     * (it reports success instead of re-running migrations / re-flipping the symlink).
     * Without this the duplicate kept re-stamping the "running" state, which is why the UI
     * never self-healed and hung on "Instalando…".
     */
    public function update(): bool
    {
        $release = $this->availableRelease();

        if ($release === null) {
            $this->setState('failed', null, 'No hay ninguna versión disponible para instalar.');

            return false;
        }

        // A duplicate attempt for an already-installed version just confirms success.
        if (Cache::get(self::DONE_KEY.$release->version) === true) {
            $this->setState('success', $release->version, "Actualizado a la versión {$release->version}.");

            return true;
        }

        $lock = Cache::lock(self::LOCK_KEY.$release->version, 1800);

        // Another attempt already holds the deploy — don't touch state or double-deploy.
        if (! $lock->get()) {
            return false;
        }

        try {
            $this->setState('running', $release->version, "Instalando la versión {$release->version}…");

            if ($this->deployer->deploy($release)) {
                // Mark done BEFORE anything that could kill this worker, so a re-reserved
                // duplicate short-circuits to success. Record success BEFORE restarting
                // workers — restartWorkers() terminates this very worker (horizon:terminate),
                // so doing it first would lose the result and hang the UI on "Installing…".
                Cache::forever(self::DONE_KEY.$release->version, true);
                $this->setState('success', $release->version, "Actualizado a la versión {$release->version}.");
                $this->deployer->restartWorkers();

                return true;
            }

            $this->deployer->rollback();
            $this->setState('failed', $release->version, 'La actualización falló el health check; se revirtió a la versión anterior.');

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Flag the in-flight run as failed (called from RunUpdateJob::failed() when the worker
     * times out or the job throws), so the UI leaves "Instalando…" instead of hanging.
     */
    public function markFailed(?string $reason = null): void
    {
        $release = $this->availableRelease();
        $message = $reason !== null && $reason !== ''
            ? 'La actualización no finalizó: '.$reason
            : 'La actualización no finalizó. Reintenta o usa Rollback.';

        $this->setState('failed', $release?->version, $message);
    }

    private function setState(string $status, ?string $version, string $message): void
    {
        Cache::forever(self::STATE_KEY, [
            'status' => $status,
            'version' => $version,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ]);
    }

    public function rollback(): void
    {
        $this->deployer->rollback();
    }
}
