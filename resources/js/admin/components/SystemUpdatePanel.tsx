import { RefreshCw } from 'lucide-react';
import { type ReactElement } from 'react';

import { useCheckUpdates, useRestartWorkers, useRollback, useRunUpdate, useUpdateStatus } from '../api';
import { Button, Card } from './ui';

/**
 * App update / rollback controls (CLAUDE.md §12). PLATFORM-ONLY in the multi-agency SaaS
 * (a single agency must never update the whole application) — rendered in the platform panel.
 */
export function SystemUpdatePanel(): ReactElement {
    const { data: status, isLoading } = useUpdateStatus();
    const check = useCheckUpdates();
    const runUpdate = useRunUpdate();
    const rollback = useRollback();
    const restartWorkers = useRestartWorkers();

    if (isLoading || status === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando estado del sistema…</p>;
    }

    const run = status.last_run;
    const inFlight = run.status === 'queued' || run.status === 'running';

    const onUpdate = (): void => {
        if (window.confirm('¿Actualizar a la versión disponible? Se hace un respaldo y, si el health check falla, se revierte solo.')) {
            runUpdate.mutate();
        }
    };
    const onRollback = (): void => {
        if (window.confirm('¿Volver a la versión anterior (rollback)?')) {
            rollback.mutate();
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Actualizaciones del sistema" description="Controla la versión de la aplicación para toda la plataforma.">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    {status.update_available ? (
                        <div className="ir-rounded-md ir-border ir-border-amber-300 ir-bg-amber-50 ir-p-3 ir-text-sm ir-text-amber-800">
                            ⬆️ <strong>Hay una actualización disponible: v{(status.available ?? '').replace(/^v/, '')}</strong>. Estás
                            ejecutando v{(status.current ?? '').replace(/^v/, '')}. Pulsa «Actualizar ahora» y espera a que termine (no recargues durante el proceso).
                        </div>
                    ) : (
                        <div className="ir-rounded-md ir-border ir-border-emerald-300 ir-bg-emerald-50 ir-p-3 ir-text-sm ir-text-emerald-800">
                            ✓ Estás en la última versión registrada (v{(status.current ?? '').replace(/^v/, '')}).
                        </div>
                    )}

                    <div className="ir-grid ir-grid-cols-1 ir-gap-4 sm:ir-grid-cols-2">
                        <div className="ir-rounded-md ir-border ir-p-3">
                            <p className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">Ejecutando ahora</p>
                            <p className="ir-font-mono ir-text-2xl ir-font-semibold">v{(status.current ?? '—').replace(/^v/, '')}</p>
                            <p className="ir-text-xs ir-text-muted-foreground">La versión real del código en el servidor.</p>
                        </div>
                        <div className="ir-rounded-md ir-border ir-p-3">
                            <p className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">Última publicada</p>
                            <p className="ir-font-mono ir-text-2xl ir-font-semibold">v{(status.available ?? '—').replace(/^v/, '')}</p>
                            <p className="ir-text-xs ir-text-muted-foreground">La más reciente registrada desde GitHub.</p>
                        </div>
                    </div>

                    {status.worker_version !== null && status.worker_version !== status.current && (
                        <div className="ir-rounded-md ir-border ir-border-red-300 ir-bg-red-50 ir-p-3 ir-text-sm ir-text-red-700">
                            ⚠ <strong>El worker de la cola corre v{status.worker_version.replace(/^v/, '')}</strong> pero el servidor web corre
                            v{(status.current ?? '').replace(/^v/, '')}. Los reportes los genera el worker, así que saldrán con el código viejo hasta reiniciarlo.
                        </div>
                    )}

                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                        <Button variant="ghost" onClick={() => check.mutate()} disabled={check.isPending}>
                            <RefreshCw className={check.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                            {check.isPending ? 'Buscando…' : 'Buscar actualizaciones'}
                        </Button>
                        <Button variant="ghost" onClick={() => restartWorkers.mutate()} disabled={restartWorkers.isPending}>
                            <RefreshCw className={restartWorkers.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                            {restartWorkers.isPending ? 'Reiniciando…' : 'Reiniciar trabajadores'}
                        </Button>
                    </div>

                    <div className="ir-flex ir-gap-3">
                        <Button onClick={onUpdate} disabled={!status.update_available || runUpdate.isPending || inFlight}>
                            {runUpdate.isPending || inFlight ? 'Actualizando…' : 'Actualizar ahora'}
                        </Button>
                        <Button variant="ghost" onClick={onRollback} disabled={rollback.isPending}>
                            {rollback.isPending ? 'Revirtiendo…' : 'Rollback'}
                        </Button>
                    </div>

                    {(run.status === 'queued' || run.status === 'running') && (
                        <div className="ir-rounded-md ir-border ir-bg-muted ir-p-3 ir-text-sm">
                            <p className="ir-flex ir-items-center ir-gap-2 ir-font-medium">
                                <RefreshCw className="ir-size-4 ir-animate-spin" />
                                {run.status === 'queued' ? 'Actualización en cola…' : (run.message || 'Instalando…')}
                            </p>
                            <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                Corre en segundo plano: respaldo → descarga → migración → cambio atómico → health check. Esta vista se refresca sola.
                            </p>
                        </div>
                    )}
                    {run.status === 'success' && <p className="ir-text-sm ir-text-emerald-600">✓ {run.message}</p>}
                    {run.status === 'failed' && <p className="ir-text-sm ir-text-red-500">✗ {run.message}</p>}
                    {runUpdate.isError && <p className="ir-text-xs ir-text-red-500">No se pudo encolar la actualización.</p>}
                    {rollback.isSuccess && <p className="ir-text-xs ir-text-emerald-600">Rollback ejecutado.</p>}
                </div>
            </Card>
        </div>
    );
}
