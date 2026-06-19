import { type ReactElement } from 'react';

import { useAuthUser, useRollback, useRunUpdate, useUpdateStatus } from '../api';
import { Button, Card } from '../components/ui';

export function SystemScreen(): ReactElement {
    const { data: user } = useAuthUser();
    const { data: status, isLoading } = useUpdateStatus();
    const runUpdate = useRunUpdate();
    const rollback = useRollback();

    const privileged = user?.role === 'owner' || user?.role === 'admin';

    if (isLoading || status === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando estado del sistema…</p>;
    }

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

                    {privileged ? (
                        <div className="ir-flex ir-gap-3">
                            <Button onClick={onUpdate} disabled={!status.update_available || runUpdate.isPending}>
                                {runUpdate.isPending ? 'Encolando…' : 'Actualizar ahora'}
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

                    {runUpdate.isSuccess && (
                        <p className="ir-text-xs ir-text-emerald-600">
                            Actualización encolada. Corre en segundo plano: respaldo → descarga → migración → cambio atómico → health check.
                        </p>
                    )}
                    {runUpdate.isError && <p className="ir-text-xs ir-text-red-500">No se pudo encolar la actualización.</p>}
                    {rollback.isSuccess && <p className="ir-text-xs ir-text-emerald-600">Rollback ejecutado.</p>}
                    {rollback.isError && <p className="ir-text-xs ir-text-red-500">No se pudo ejecutar el rollback.</p>}
                </div>
            </Card>

            <Card title="Cómo funciona">
                <ul className="ir-flex ir-list-disc ir-flex-col ir-gap-2 ir-pl-5 ir-text-sm ir-text-muted-foreground">
                    <li>Las versiones se publican como paquetes de release; la «versión disponible» aparece cuando hay una nueva registrada.</li>
                    <li>«Actualizar ahora» respalda la base de datos, despliega la versión nueva al lado y cambia el enlace de forma atómica; si el health check falla, revierte solo.</li>
                    <li>«Rollback» vuelve a la versión anterior al instante (el release previo se conserva intacto).</li>
                </ul>
            </Card>
        </div>
    );
}
