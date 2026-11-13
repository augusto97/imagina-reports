import { KeyRound } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useCheckUpdates, usePlatformIntegrations, useUpdatePlatformIntegrations } from '../api';
import { Button, Card, Input } from './ui';

const TOKEN_FIELD = 'github_updater_token';

/**
 * «Acceso a GitHub»: a read-only token so the server can see and download the releases of a
 * PRIVATE repository. Saved encrypted; only «guardado / no guardado» ever comes back.
 */
export function GithubAccessCard(): ReactElement {
    const { data: settings } = usePlatformIntegrations();
    const save = useUpdatePlatformIntegrations();
    const check = useCheckUpdates();
    const [token, setToken] = useState('');
    const saved = settings?.github_updater_token_set === true;

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        const value = token.trim();
        if (value === '') {
            return;
        }
        save.mutate(
            { [TOKEN_FIELD]: value },
            {
                onSuccess: () => {
                    setToken('');
                    // Look again right away with the token, so the new version shows.
                    check.mutate();
                },
            },
        );
    };

    const remove = (): void => {
        if (window.confirm('¿Quitar el token? Si el repositorio es privado, la app dejará de ver las versiones nuevas.')) {
            save.mutate({ [TOKEN_FIELD]: '' });
        }
    };

    return (
        <Card>
            <div className="ir-flex ir-items-start ir-gap-3">
                <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-muted ir-text-muted-foreground">
                    <KeyRound className="ir-size-4" />
                </span>
                <div className="ir-min-w-0 ir-flex-1">
                    <p className="ir-text-sm ir-font-semibold">Acceso a GitHub</p>
                    <p className="ir-mt-0.5 ir-text-[13px] ir-text-muted-foreground">
                        Solo hace falta si el repositorio es privado: con un token de solo lectura, el servidor puede ver y descargar las versiones
                        nuevas. {saved ? 'Hay un token guardado.' : 'Si el repositorio es público, déjalo vacío.'}
                    </p>

                    <details className="ir-mt-2 ir-text-[13px]">
                        <summary className="ir-cursor-pointer ir-font-medium ir-text-accent">Cómo crear el token</summary>
                        <ol className="ir-mt-2 ir-list-decimal ir-space-y-1 ir-pl-5 ir-text-muted-foreground">
                            <li>
                                En GitHub, abre <strong>Settings → Developer settings → Personal access tokens → Fine-grained tokens</strong> y pulsa{' '}
                                <strong>Generate new token</strong>.
                            </li>
                            <li>
                                En <strong>Repository access</strong> elige <strong>Only select repositories</strong> y marca solo el repositorio de Imagina
                                Reports.
                            </li>
                            <li>
                                En <strong>Permissions → Repository permissions</strong>, pon <strong>Contents</strong> en <strong>Read-only</strong>. No
                                hace falta nada más.
                            </li>
                            <li>Elige una fecha de caducidad, genera el token (empieza por «github_pat_») y pégalo aquí.</li>
                        </ol>
                    </details>

                    <form onSubmit={submit} className="ir-mt-3 ir-flex ir-flex-wrap ir-items-center ir-gap-2">
                        <Input
                            type="password"
                            autoComplete="off"
                            value={token}
                            onChange={(event) => setToken(event.target.value)}
                            placeholder={saved ? '•••••••• (pega uno nuevo para reemplazarlo)' : 'github_pat_…'}
                            aria-label="Token de GitHub"
                            className="ir-min-w-0 ir-flex-1 sm:ir-max-w-sm"
                        />
                        <Button type="submit" size="sm" disabled={save.isPending || token.trim() === ''}>
                            {save.isPending ? 'Guardando…' : saved ? 'Reemplazar' : 'Guardar'}
                        </Button>
                        {saved && (
                            <Button type="button" size="sm" variant="ghost" onClick={remove} disabled={save.isPending}>
                                Quitar
                            </Button>
                        )}
                    </form>
                    {save.isError && <p className="ir-mt-2 ir-text-xs ir-text-danger">No se pudo guardar el token.</p>}
                    {save.isSuccess && !save.isPending && <p className="ir-mt-2 ir-text-xs ir-text-success">Guardado.</p>}
                </div>
            </div>
        </Card>
    );
}
