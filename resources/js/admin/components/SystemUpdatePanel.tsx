import { ArrowUpCircle, CheckCircle2, Cpu, History, RefreshCw, Server, TriangleAlert } from 'lucide-react';
import { type ReactElement } from 'react';

import { useCheckUpdates, useRestartWorkers, useRollback, useRunUpdate, useUpdateStatus } from '../api';
import { ActionMenu, Button, Card, StatCard } from './ui';

function version(value: string | null | undefined): string {
    return value === null || value === undefined || value === '' ? 'Sin datos' : `v${value.replace(/^v/, '')}`;
}

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
    const workerBehind = status.worker_version !== null && status.worker_version !== status.current;

    const onUpdate = (): void => {
        if (window.confirm('¿Actualizar a la versión disponible? Se hace un respaldo y, si el health check falla, se revierte solo.')) {
            runUpdate.mutate();
        }
    };
    const onRollback = (): void => {
        if (window.confirm('¿Volver a la versión anterior? Se restaura también la base de datos del respaldo.')) {
            rollback.mutate();
        }
    };

    return (
        <div className="ir-flex ir-max-w-5xl ir-flex-col ir-gap-5">
            {/* The one thing to know first: up to date, or not. */}
            <div
                className={`ir-flex ir-flex-wrap ir-items-center ir-gap-4 ir-rounded-xl ir-border ir-p-5 ${
                    status.update_available ? 'ir-border-accent/30 ir-bg-accent/[0.06]' : 'ir-bg-card ir-shadow-ir-xs'
                }`}
            >
                <span className={`ir-flex ir-size-10 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ${status.update_available ? 'ir-bg-accent ir-text-accent-foreground' : 'ir-bg-success/10 ir-text-success'}`}>
                    {status.update_available ? <ArrowUpCircle className="ir-size-5" /> : <CheckCircle2 className="ir-size-5" />}
                </span>
                <div className="ir-min-w-0 ir-flex-1">
                    <p className="ir-text-[15px] ir-font-semibold ir-tracking-tight">
                        {status.update_available ? `Hay una versión nueva: ${version(status.available)}` : 'La plataforma está al día'}
                    </p>
                    <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">
                        {status.update_available
                            ? 'Se hace un respaldo, se instala al lado de la actual y se cambia en un instante. Si algo falla, vuelve sola a la anterior.'
                            : `Estás en ${version(status.current)}. Busca de nuevo cuando publiques una versión.`}
                    </p>
                </div>
                <div className="ir-flex ir-items-center ir-gap-2">
                    {status.update_available ? (
                        <Button onClick={onUpdate} disabled={runUpdate.isPending || inFlight}>
                            {runUpdate.isPending || inFlight ? 'Actualizando…' : 'Actualizar ahora'}
                        </Button>
                    ) : (
                        <Button variant="ghost" onClick={() => check.mutate()} disabled={check.isPending}>
                            <RefreshCw className={check.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                            {check.isPending ? 'Buscando…' : 'Buscar actualizaciones'}
                        </Button>
                    )}
                    <ActionMenu
                        label="Más acciones del sistema"
                        items={[
                            status.update_available && { label: 'Buscar actualizaciones', icon: RefreshCw, onSelect: () => check.mutate(), disabled: check.isPending },
                            { label: 'Reiniciar trabajadores de la cola', icon: Cpu, onSelect: () => restartWorkers.mutate(), disabled: restartWorkers.isPending },
                            { label: 'Volver a la versión anterior', icon: History, onSelect: onRollback, danger: true, disabled: rollback.isPending },
                        ]}
                    />
                </div>
            </div>

            {inFlight && (
                <Card>
                    <p className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-font-medium">
                        <RefreshCw className="ir-size-4 ir-animate-spin" />
                        {run.status === 'queued' ? 'Actualización en cola…' : run.message || 'Instalando…'}
                    </p>
                    <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                        Corre en segundo plano: respaldo → descarga → migración → cambio atómico → health check. Esta vista se refresca sola.
                    </p>
                </Card>
            )}

            {workerBehind && (
                <div className="ir-flex ir-items-start ir-gap-3 ir-rounded-xl ir-border ir-border-danger/30 ir-bg-danger/[0.06] ir-p-4 ir-text-sm">
                    <TriangleAlert className="ir-mt-0.5 ir-size-4 ir-shrink-0 ir-text-danger" />
                    <div className="ir-flex-1">
                        <p className="ir-font-medium">La cola corre {version(status.worker_version)} y la web {version(status.current)}</p>
                        <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">Los reportes los genera la cola: saldrán con el código viejo hasta reiniciarla.</p>
                    </div>
                    <Button size="sm" variant="ghost" onClick={() => restartWorkers.mutate()} disabled={restartWorkers.isPending}>
                        {restartWorkers.isPending ? 'Reiniciando…' : 'Reiniciar'}
                    </Button>
                </div>
            )}

            <div className="ir-grid ir-gap-3 sm:ir-grid-cols-3">
                <StatCard label="Instalada" value={version(status.current)} hint="El código que corre en el servidor" icon={Server} />
                <StatCard label="Última publicada" value={version(status.available)} hint="Registrada desde GitHub" icon={ArrowUpCircle} tone={status.update_available ? 'accent' : 'neutral'} />
                <StatCard label="Cola de trabajos" value={version(status.worker_version ?? status.current)} hint={workerBehind ? 'Desactualizada' : 'Al día con la web'} icon={Cpu} tone={workerBehind ? 'danger' : 'success'} />
            </div>

            {run.status === 'success' && run.message !== '' && <p className="ir-text-sm ir-text-success">✓ {run.message}</p>}
            {run.status === 'failed' && <p className="ir-text-sm ir-text-danger">✗ {run.message}</p>}
            {runUpdate.isError && <p className="ir-text-xs ir-text-danger">No se pudo encolar la actualización.</p>}
            {rollback.isSuccess && <p className="ir-text-xs ir-text-success">Se volvió a la versión anterior.</p>}
            {restartWorkers.isSuccess && <p className="ir-text-xs ir-text-success">Trabajadores reiniciados.</p>}
        </div>
    );
}
