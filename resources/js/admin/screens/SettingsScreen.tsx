import { type ReactElement, useEffect, useState } from 'react';

import { type AgencyUpdate, useAgency, useChangePassword, usePruneSnapshots, useRetentionPreview, useUpdateAgency, useUploadLogo } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

/** Humanize a byte count: 0 B / 12 KB / 3.4 MB. */
function humanBytes(bytes: number): string {
    if (bytes <= 0) {
        return '0 B';
    }
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

/** Shows what the saved retention setting would free, with a manual "free now" action. */
function RetentionUsage(): ReactElement {
    const { data: preview } = useRetentionPreview();
    const prune = usePruneSnapshots();

    if (preview === undefined || preview.snapshots === 0) {
        return (
            <p className="ir-text-xs ir-text-muted-foreground">
                Con el límite guardado, ahora mismo no hay datos antiguos por liberar.
            </p>
        );
    }

    return (
        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3 ir-rounded-md ir-bg-muted/50 ir-p-3">
            <span className="ir-text-xs ir-text-muted-foreground">
                Liberable ahora: <strong>{preview.snapshots}</strong> {preview.snapshots === 1 ? 'periodo' : 'periodos'} · {humanBytes(preview.bytes)}
            </span>
            <Button variant="outline" size="sm" onClick={() => prune.mutate()} disabled={prune.isPending}>
                {prune.isPending ? 'Liberando…' : 'Liberar ahora'}
            </Button>
            {prune.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Liberados {prune.data?.deleted ?? 0} periodos.</span>}
        </div>
    );
}

function PasswordCard(): ReactElement {
    const change = useChangePassword();
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');

    const submit = (): void => {
        setError('');
        if (next !== confirm) {
            setError('La nueva contraseña y su confirmación no coinciden.');

            return;
        }
        change.mutate(
            { current_password: current, password: next, password_confirmation: confirm },
            {
                onSuccess: () => {
                    setCurrent('');
                    setNext('');
                    setConfirm('');
                },
                onError: () => setError('No se pudo cambiar. Revisa que la contraseña actual sea correcta y la nueva tenga al menos 8 caracteres.'),
            },
        );
    };

    return (
        <Card title="Cuenta — cambiar contraseña">
            <div className="ir-flex ir-flex-col ir-gap-4">
                <Field label="Contraseña actual">
                    <Input type="password" autoComplete="current-password" value={current} onChange={(event) => setCurrent(event.target.value)} />
                </Field>
                <Field label="Nueva contraseña (mín. 8)">
                    <Input type="password" autoComplete="new-password" value={next} onChange={(event) => setNext(event.target.value)} />
                </Field>
                <Field label="Repite la nueva contraseña">
                    <Input type="password" autoComplete="new-password" value={confirm} onChange={(event) => setConfirm(event.target.value)} />
                </Field>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={submit} disabled={change.isPending || current === '' || next === ''}>
                        {change.isPending ? 'Cambiando…' : 'Cambiar contraseña'}
                    </Button>
                    {change.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Contraseña actualizada.</span>}
                    {error !== '' && <span className="ir-text-xs ir-text-red-500">{error}</span>}
                </div>
            </div>
        </Card>
    );
}

const LOCALES: { value: string; label: string }[] = [
    { value: 'es', label: 'Español' },
    { value: 'en', label: 'English' },
    { value: 'pt_BR', label: 'Português (BR)' },
];

export function SettingsScreen(): ReactElement {
    const { data: agency, isLoading } = useAgency();
    const update = useUpdateAgency();
    const uploadLogo = useUploadLogo();

    const [name, setName] = useState('');
    const [color, setColor] = useState('#6d28d9');
    const [locale, setLocale] = useState('es');
    const [apiKey, setApiKey] = useState('');
    const [retention, setRetention] = useState('');

    useEffect(() => {
        if (agency !== undefined) {
            setName(agency.name);
            setColor(agency.brand_color ?? '#6d28d9');
            setLocale(agency.default_locale);
            setRetention(agency.snapshot_retention_months === null ? '' : String(agency.snapshot_retention_months));
        }
    }, [agency]);

    if (isLoading || agency === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando ajustes…</p>;
    }

    const save = (): void => {
        const payload: AgencyUpdate = {
            name,
            brand_color: color,
            default_locale: locale,
            snapshot_retention_months: retention === '' ? null : Number(retention),
        };
        if (apiKey !== '') {
            payload.anthropic_key = apiKey;
        }
        update.mutate(payload, { onSuccess: () => setApiKey('') });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Marca (white-label)">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <Field label="Nombre de la agencia">
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Color de marca">
                        <div className="ir-flex ir-items-center ir-gap-3">
                            <input
                                type="color"
                                value={color}
                                onChange={(event) => setColor(event.target.value)}
                                className="ir-h-9 ir-w-12 ir-rounded ir-border"
                            />
                            <Input value={color} onChange={(event) => setColor(event.target.value)} className="ir-w-32" />
                        </div>
                    </Field>
                    <Field label="Idioma por defecto">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={locale}
                            onChange={(event) => setLocale(event.target.value)}
                        >
                            {LOCALES.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Logo">
                        <div className="ir-flex ir-items-center ir-gap-4">
                            {agency.logo_url !== null && (
                                <img src={agency.logo_url} alt="Logo" className="ir-h-10 ir-rounded ir-border ir-bg-white ir-p-1" />
                            )}
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/svg+xml,image/webp"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (file !== undefined) {
                                        uploadLogo.mutate(file);
                                    }
                                }}
                                className="ir-text-sm"
                            />
                            {uploadLogo.isPending && <span className="ir-text-xs ir-text-muted-foreground">Subiendo…</span>}
                        </div>
                    </Field>
                </div>
            </Card>

            <Card title="Inteligencia artificial (Claude)">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <p className="ir-text-sm ir-text-muted-foreground">
                        La clave se guarda cifrada y nunca se muestra. Estado actual:{' '}
                        {agency.ai_key_set ? (
                            <span className="ir-font-medium ir-text-emerald-600">configurada ✓</span>
                        ) : (
                            <span className="ir-font-medium ir-text-amber-600">sin configurar</span>
                        )}
                        .
                    </p>
                    <Field label="Anthropic API key">
                        <Input
                            type="password"
                            autoComplete="off"
                            placeholder={agency.ai_key_set ? '•••••••• (deja en blanco para conservar)' : 'sk-ant-…'}
                            value={apiKey}
                            onChange={(event) => setApiKey(event.target.value)}
                        />
                    </Field>
                </div>
            </Card>

            <Card title="Retención de datos">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <p className="ir-text-sm ir-text-muted-foreground">
                        Limita cuánto tiempo se guardan los datos sincronizados (snapshots) para que no llenen el servidor. Los reportes ya
                        generados no se ven afectados (guardan su propia copia), y siempre se conserva el último dato de cada fuente.
                    </p>
                    <Field label="Conservar datos de">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={retention}
                            onChange={(event) => setRetention(event.target.value)}
                        >
                            <option value="">Sin límite (conservar todo)</option>
                            <option value="3">Últimos 3 meses</option>
                            <option value="6">Últimos 6 meses</option>
                            <option value="12">Último año</option>
                            <option value="24">Últimos 2 años</option>
                            <option value="36">Últimos 3 años</option>
                        </select>
                    </Field>
                    <RetentionUsage />
                </div>
            </Card>

            <div className="ir-flex ir-items-center ir-gap-3">
                <Button onClick={save} disabled={update.isPending || name === ''}>
                    {update.isPending ? 'Guardando…' : 'Guardar ajustes'}
                </Button>
                {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Ajustes guardados.</span>}
                {update.isError && <span className="ir-text-xs ir-text-red-500">No se pudieron guardar.</span>}
            </div>

            <PasswordCard />
        </div>
    );
}
