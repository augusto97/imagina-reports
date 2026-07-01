import { Plus, Send, Trash2, Webhook } from 'lucide-react';
import { type ReactElement, useEffect, useState } from 'react';

import { type AgencyUpdate, useAgency, useChangePassword, usePruneSnapshots, useRetentionPreview, useTestWebhooks, useUpdateAgency, useUploadLogo } from '../api';
import { Badge, Button, Card, Field, Input } from '../components/ui';
import type { AgencySettings } from '../types';

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

const WEBHOOK_EVENTS = ['report.generated', 'report.sent', 'anomaly.detected', 'upsell.detected'];

/**
 * Outbound webhooks / integrations (CLAUDE.md §8). Self-contained so saving here never
 * clobbers unsaved edits in the main form: it re-sends the agency's SAVED branding
 * alongside the webhook fields (the update endpoint expects them).
 */
function WebhooksCard({ agency }: { agency: AgencySettings }): ReactElement {
    const update = useUpdateAgency();
    const test = useTestWebhooks();
    const [urls, setUrls] = useState<string[]>(agency.webhook_urls.length > 0 ? agency.webhook_urls : ['']);
    const [secret, setSecret] = useState('');

    const setUrl = (index: number, value: string): void => setUrls((prev) => prev.map((url, i) => (i === index ? value : url)));
    const addUrl = (): void => setUrls((prev) => [...prev, '']);
    const removeUrl = (index: number): void => setUrls((prev) => prev.filter((_, i) => i !== index));

    const save = (): void => {
        const payload: AgencyUpdate = {
            name: agency.name,
            brand_color: agency.brand_color,
            default_locale: agency.default_locale,
            webhook_urls: urls.map((url) => url.trim()).filter((url) => url !== ''),
        };
        if (secret !== '') {
            payload.webhook_secret = secret;
        }
        update.mutate(payload, { onSuccess: () => setSecret('') });
    };

    return (
        <Card
            title="Webhooks e integraciones"
            description="Envía eventos a Zapier, Make, un CRM, Slack… en cuanto ocurren. Cada endpoint recibe un POST con el evento y sus datos."
        >
            <div className="ir-flex ir-flex-col ir-gap-4">
                <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                    {WEBHOOK_EVENTS.map((event) => (
                        <Badge key={event} tone="neutral">
                            <code>{event}</code>
                        </Badge>
                    ))}
                </div>

                <Field label="Endpoints (URLs)">
                    <div className="ir-flex ir-flex-col ir-gap-2">
                        {urls.map((url, index) => (
                            <div key={index} className="ir-flex ir-items-center ir-gap-2">
                                <span className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-muted ir-text-muted-foreground">
                                    <Webhook className="ir-size-4" />
                                </span>
                                <Input value={url} onChange={(event) => setUrl(index, event.target.value)} placeholder="https://tu-servicio.com/webhook" />
                                <button type="button" title="Quitar" onClick={() => removeUrl(index)} className="ir-shrink-0 ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-danger/10 hover:ir-text-danger">
                                    <Trash2 className="ir-size-4" />
                                </button>
                            </div>
                        ))}
                        <Button type="button" variant="ghost" size="sm" className="ir-self-start" onClick={addUrl}>
                            <Plus className="ir-size-3.5" />
                            Añadir endpoint
                        </Button>
                    </div>
                </Field>

                <Field label="Secreto de firma (opcional)" hint={agency.webhook_secret_set ? 'Ya hay un secreto guardado. Escribe uno nuevo para reemplazarlo.' : 'Se envía como cabecera de firma HMAC para que tu servicio verifique el origen.'}>
                    <Input
                        type="password"
                        autoComplete="off"
                        value={secret}
                        onChange={(event) => setSecret(event.target.value)}
                        placeholder={agency.webhook_secret_set ? '•••••••• (deja en blanco para conservar)' : 'Un secreto compartido'}
                    />
                </Field>

                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                    <Button onClick={save} disabled={update.isPending}>
                        {update.isPending ? 'Guardando…' : 'Guardar webhooks'}
                    </Button>
                    <Button variant="outline" onClick={() => test.mutate()} disabled={test.isPending || agency.webhook_urls.length === 0} title={agency.webhook_urls.length === 0 ? 'Guarda al menos un endpoint primero' : 'Envía un evento de prueba a los endpoints guardados'}>
                        <Send className="ir-size-3.5" />
                        {test.isPending ? 'Enviando…' : 'Probar'}
                    </Button>
                    {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Webhooks guardados.</span>}
                    {test.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Evento de prueba enviado a {test.data?.sent ?? 0} endpoint(s).</span>}
                    {test.isError && <span className="ir-text-xs ir-text-red-500">No se pudo enviar la prueba.</span>}
                </div>
                <p className="ir-text-xs ir-text-muted-foreground">La prueba usa los endpoints ya guardados: guarda antes de probar cambios.</p>
            </div>
        </Card>
    );
}

/** The agency's plan + live usage against its limits (SaaS Fase 1). */
function PlanUsageCard({ agency }: { agency: AgencySettings }): ReactElement {
    const rows: { label: string; used: number; limit: number | null }[] = [
        { label: 'Sitios', used: agency.usage.sites, limit: agency.limits.max_sites },
        { label: 'Fuentes de datos', used: agency.usage.data_sources, limit: agency.limits.max_data_sources },
        { label: 'Clientes', used: agency.usage.clients, limit: agency.limits.max_clients },
        { label: 'Reportes este mes', used: agency.usage.reports_this_month, limit: agency.limits.max_reports_per_month },
    ];

    return (
        <Card title="Plan y uso" description={agency.plan !== null ? `Estás en el plan ${agency.plan.name}.` : 'Sin plan asignado (sin límites).'}>
            <div className="ir-grid ir-gap-4 sm:ir-grid-cols-2">
                {rows.map((row) => {
                    const pct = row.limit === null || row.limit === 0 ? 0 : Math.min(100, (row.used / row.limit) * 100);
                    const over = row.limit !== null && row.used >= row.limit;

                    return (
                        <div key={row.label}>
                            <div className="ir-flex ir-justify-between ir-text-xs ir-text-muted-foreground">
                                <span>{row.label}</span>
                                <span className={over ? 'ir-font-medium ir-text-danger' : ''}>
                                    {row.used}/{row.limit ?? '∞'}
                                </span>
                            </div>
                            <div className="ir-mt-1 ir-h-1.5 ir-overflow-hidden ir-rounded ir-bg-muted">
                                <div className={`ir-h-full ir-rounded ${over ? 'ir-bg-danger' : 'ir-bg-primary'}`} style={{ width: `${row.limit === null ? 6 : pct}%` }} />
                            </div>
                        </div>
                    );
                })}
            </div>
        </Card>
    );
}

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
            <PlanUsageCard agency={agency} />

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

            <WebhooksCard agency={agency} />

            <PasswordCard />
        </div>
    );
}
