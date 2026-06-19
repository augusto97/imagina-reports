import { RefreshCw } from 'lucide-react';
import { type ReactElement } from 'react';

import { useAuthUser, useCheckUpdates, useRollback, useRunUpdate, useUpdateStatus } from '../api';
import { Button, Card } from '../components/ui';

export function SystemScreen(): ReactElement {
    const { data: user } = useAuthUser();
    const { data: status, isLoading } = useUpdateStatus();
    const check = useCheckUpdates();
    const runUpdate = useRunUpdate();
    const rollback = useRollback();

    const privileged = user?.role === 'owner' || user?.role === 'admin';

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
            <Card title="Actualizaciones del sistema">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <div className="ir-grid ir-grid-cols-2 ir-gap-4">
                        <div>
                            <p className="ir-text-xs ir-text-muted-foreground">Versión instalada</p>
                            <p className="ir-text-2xl ir-font-semibold">{status.current ?? '—'}</p>
                        </div>
                        <div>
                            <p className="ir-text-xs ir-text-muted-foreground">Versión disponible</p>
                            <p className="ir-text-2xl ir-font-semibold">{status.available ?? '—'}</p>
                        </div>
                    </div>

                    <p className="ir-text-sm">
                        {status.update_available
                            ? `Hay una actualización disponible (${status.available ?? ''}).`
                            : 'Estás en la última versión registrada.'}
                    </p>

                    <div className="ir-flex ir-items-center ir-gap-3">
                        <Button variant="ghost" onClick={() => check.mutate()} disabled={check.isPending}>
                            <RefreshCw className={check.isPending ? 'ir-size-4 ir-animate-spin' : 'ir-size-4'} />
                            {check.isPending ? 'Buscando…' : 'Buscar actualizaciones'}
                        </Button>
                        {check.isSuccess && !status.update_available && (
                            <span className="ir-text-xs ir-text-muted-foreground">
                                Sin novedades: ya estás en la última versión.
                            </span>
                        )}
                        {check.isSuccess && status.update_available && (
                            <span className="ir-text-xs ir-text-emerald-600">
                                Nueva versión encontrada: {status.available ?? ''}.
                            </span>
                        )}
                        {check.isError && (
                            <span className="ir-text-xs ir-text-red-500">No se pudo consultar GitHub.</span>
                        )}
                    </div>

                    {privileged ? (
                        <div className="ir-flex ir-gap-3">
                            <Button onClick={onUpdate} disabled={!status.update_available || runUpdate.isPending || inFlight}>
                                {runUpdate.isPending || inFlight ? 'Actualizando…' : 'Actualizar ahora'}
                            </Button>
                            <Button variant="ghost" onClick={onRollback} disabled={rollback.isPending}>
                                {rollback.isPending ? 'Revirtiendo…' : 'Rollback'}
                            </Button>
                        </div>
                    ) : (
                        <p className="ir-text-xs ir-text-muted-foreground">
                            Solo un propietario o administrador puede actualizar o revertir.
                        </p>
                    )}

                    {(run.status === 'queued' || run.status === 'running') && (
                        <div className="ir-rounded-md ir-border ir-bg-muted ir-p-3 ir-text-sm">
                            <p className="ir-flex ir-items-center ir-gap-2 ir-font-medium">
                                <RefreshCw className="ir-size-4 ir-animate-spin" />
                                {run.status === 'queued' ? 'Actualización en cola…' : (run.message || 'Instalando…')}
                            </p>
                            <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                Corre en segundo plano: respaldo → descarga → migración → cambio atómico → health check. Esta vista se
                                refresca sola. Si se queda «en cola» varios minutos, revisa que el worker de la cola (Horizon) esté activo.
                            </p>
                        </div>
                    )}
                    {run.status === 'success' && (
                        <p className="ir-text-sm ir-text-emerald-600">✓ {run.message}</p>
                    )}
                    {run.status === 'failed' && (
                        <p className="ir-text-sm ir-text-red-500">✗ {run.message}</p>
                    )}
                    {runUpdate.isError && <p className="ir-text-xs ir-text-red-500">No se pudo encolar la actualización.</p>}
                    {rollback.isSuccess && <p className="ir-text-xs ir-text-emerald-600">Rollback ejecutado.</p>}
                    {rollback.isError && <p className="ir-text-xs ir-text-red-500">No se pudo ejecutar el rollback.</p>}
                </div>
            </Card>

            <Card title="Cómo funciona">
                <ul className="ir-flex ir-list-disc ir-flex-col ir-gap-2 ir-pl-5 ir-text-sm ir-text-muted-foreground">
                    <li>Las versiones se publican como paquetes de release; la «versión disponible» aparece cuando hay una nueva registrada.</li>
                    <li>El sistema consulta GitHub automáticamente cada hora; «Buscar actualizaciones» fuerza esa consulta al instante.</li>
                    <li>«Actualizar ahora» respalda la base de datos, despliega la versión nueva al lado y cambia el enlace de forma atómica; si el health check falla, revierte solo.</li>
                    <li>«Rollback» vuelve a la versión anterior al instante (el release previo se conserva intacto).</li>
                </ul>
            </Card>
        </div>
    );
}
