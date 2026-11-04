import { ChevronLeft, FlaskConical, Pencil, Plus, RefreshCw, Search, Sparkles, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import {
    downloadSiteAgentPlugin,
    useConnectStart,
    useConnectors,
    useCreateDataSource,
    useDeleteDataSource,
    useDiscoverResources,
    useSiteDataSources,
    useTestConnection,
    useUpdateDataSource,
} from '../api';
import type { Ga4DatasetSpec } from '../api';
import type { ConfigFieldDef, Connector, DataSourceDto } from '../types';
import { Ga4DatasetBuilder } from './Ga4DatasetBuilder';
import { RangeSyncMenu } from './RangeSyncMenu';
import { SOURCE_CATEGORIES, SOURCE_META } from '../lib/sources';
import { ActionMenu, Button, Field, Input, StatusDot } from './ui';

/** One-click download of the companion WordPress plugin (site_agent connector). */
function SiteAgentDownload(): ReactElement {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);

    const download = (): void => {
        setBusy(true);
        setError(false);
        downloadSiteAgentPlugin()
            .catch(() => setError(true))
            .finally(() => setBusy(false));
    };

    return (
        <div className="ir-mt-3">
            <Button type="button" size="sm" onClick={download} disabled={busy}>
                {busy ? 'Preparando…' : '⬇ Descargar plugin del agente'}
            </Button>
            <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                Descarga el ZIP e instálalo en el sitio (Plugins → Añadir nuevo → Subir plugin). No necesitas descomprimirlo.
            </p>
            {error && <p className="ir-mt-1 ir-text-xs ir-text-danger">No se pudo descargar el plugin. Inténtalo de nuevo.</p>}
        </div>
    );
}

/** Collapsible "how to connect" guide for the selected connector. */
function SetupGuidePanel({ connector }: { connector: Connector }): ReactElement | null {
    const guide = connector.guide;
    if (guide == null) {
        return null;
    }

    return (
        <details className="ir-rounded-md ir-border ir-bg-muted/30 ir-p-3" open>
            <summary className="ir-cursor-pointer ir-text-sm ir-font-medium">Cómo conectar {connector.label}</summary>
            <p className="ir-mt-2 ir-text-xs ir-text-muted-foreground">{guide.intro}</p>
            <ol className="ir-mt-2 ir-list-decimal ir-space-y-1 ir-pl-5 ir-text-xs ir-text-foreground">
                {guide.steps.map((step, index) => (
                    <li key={index}>{step}</li>
                ))}
            </ol>
            {connector.key === 'site_agent' && <SiteAgentDownload />}
            {guide.docs_url != null && (
                <a
                    href={guide.docs_url}
                    target="_blank"
                    rel="noreferrer"
                    className="ir-mt-2 ir-inline-block ir-text-xs ir-text-primary ir-underline"
                >
                    Documentación oficial ↗
                </a>
            )}
        </details>
    );
}

/** Small copy-to-clipboard button for the push snippets. */
function CopyButton({ text, label = 'Copiar' }: { text: string; label?: string }): ReactElement {
    const [copied, setCopied] = useState(false);
    const copy = (): void => {
        void navigator.clipboard?.writeText(text).then(() => {
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        });
    };

    return (
        <Button type="button" variant="ghost" size="sm" onClick={copy}>
            {copied ? '¡Copiado!' : label}
        </Button>
    );
}

/**
 * Install panel for push-model sources (CrowdSec): the source isn't polled, the client
 * VPS posts its data outbound. Shows the per-source ingest URL and the ready-to-paste
 * cron line — no inbound port is opened on the client server.
 */
function PushInstallPanel({ source }: { source: DataSourceDto }): ReactElement | null {
    if (source.is_push !== true || source.ingest_url == null) {
        return null;
    }

    const cron = `echo '0 * * * * root IMAGINA_INGEST_URL="${source.ingest_url}" /usr/local/bin/imagina-crowdsec-push.sh' > /etc/cron.d/imagina-crowdsec-push`;

    return (
        <details className="ir-mt-2 ir-rounded-md ir-border ir-bg-muted/30 ir-p-3">
            <summary className="ir-cursor-pointer ir-text-sm ir-font-medium">Comando de instalación (envío desde el VPS)</summary>
            <p className="ir-mt-2 ir-text-xs ir-text-muted-foreground">
                CrowdSec corre en el VPS del cliente. En vez de abrir un puerto, el VPS <strong>envía</strong> sus datos a Imagina
                Reports. No se expone nada: es una llamada saliente por HTTPS.
            </p>
            <ol className="ir-mt-2 ir-list-decimal ir-space-y-2 ir-pl-5 ir-text-xs">
                <li>
                    Copia el script <code className="ir-rounded ir-bg-muted ir-px-1">scripts/crowdsec-push.sh</code> (incluido en el
                    paquete) a <code className="ir-rounded ir-bg-muted ir-px-1">/usr/local/bin/imagina-crowdsec-push.sh</code> en el
                    VPS del cliente y hazlo ejecutable (<code className="ir-rounded ir-bg-muted ir-px-1">chmod +x</code>).
                </li>
                <li>
                    <div className="ir-mb-1 ir-flex ir-items-center ir-justify-between ir-gap-2">
                        <span>Tu URL de envío (contiene un token secreto, no la compartas):</span>
                        <CopyButton text={source.ingest_url} label="Copiar URL" />
                    </div>
                    <pre className="ir-overflow-x-auto ir-rounded ir-bg-foreground/5 ir-p-2 ir-text-[11px]">{source.ingest_url}</pre>
                </li>
                <li>
                    <div className="ir-mb-1 ir-flex ir-items-center ir-justify-between ir-gap-2">
                        <span>Añade el cron (envío cada hora):</span>
                        <CopyButton text={cron} label="Copiar cron" />
                    </div>
                    <pre className="ir-overflow-x-auto ir-rounded ir-bg-foreground/5 ir-p-2 ir-text-[11px]">{cron}</pre>
                </li>
                <li>Al llegar el primer envío, el estado de esta fuente pasará a «ok» y verás las métricas en el reporte.</li>
            </ol>
        </details>
    );
}

/** Dynamic connector config fields driven by the connector's configSchema (§7/§11). */
function ConnectorFields({
    connector,
    values,
    onChange,
    editing,
}: {
    connector: Connector;
    values: Record<string, string>;
    onChange: (key: string, value: string) => void;
    editing?: boolean;
}): ReactElement {
    return (
        <>
            {connector.config_schema.map((field) => (
                <Field key={field.key} label={field.label} hint={field.help ?? undefined}>
                    <Input
                        type={field.secret ? 'password' : 'text'}
                        value={values[field.key] ?? ''}
                        placeholder={field.secret && editing === true ? 'Déjalo vacío para conservar el actual' : undefined}
                        onChange={(event) => onChange(field.key, event.target.value)}
                    />
                </Field>
            ))}
        </>
    );
}

/** Renders a flat list of config fields (used for the one-click connect's up-front fields). */
function FieldList({
    fields,
    values,
    onChange,
}: {
    fields: ConfigFieldDef[];
    values: Record<string, string>;
    onChange: (key: string, value: string) => void;
}): ReactElement {
    return (
        <>
            {fields.map((field) => (
                <Field key={field.key} label={field.label} hint={field.help ?? undefined}>
                    <Input
                        type={field.secret ? 'password' : 'text'}
                        value={values[field.key] ?? ''}
                        onChange={(event) => onChange(field.key, event.target.value)}
                    />
                </Field>
            ))}
        </>
    );
}

function splitConfig(connector: Connector, values: Record<string, string>): { config: Record<string, string>; credentials: Record<string, string> } {
    const config: Record<string, string> = {};
    const credentials: Record<string, string> = {};
    for (const field of connector.config_schema) {
        const value = values[field.key] ?? '';
        if (field.secret) {
            credentials[field.key] = value; // blank = keep existing (handled by the API on edit)
        } else {
            config[field.key] = value;
        }
    }

    return { config, credentials };
}

/** Inline edit form for an existing data source: reconfigure its URL/keys/token. */
function DataSourceEditForm({
    source,
    connector,
    siteId,
    onClose,
}: {
    source: DataSourceDto;
    connector: Connector | undefined;
    siteId: number;
    onClose: () => void;
}): ReactElement {
    const update = useUpdateDataSource(siteId);
    const [values, setValues] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        for (const field of connector?.config_schema ?? []) {
            if (!field.secret) {
                const current = (source.config ?? {})[field.key];
                initial[field.key] = typeof current === 'string' ? current : '';
            }
        }
        return initial;
    });

    if (connector === undefined) {
        return (
            <div className="ir-rounded-md ir-border ir-bg-muted/20 ir-p-3 ir-text-sm">
                <p className="ir-text-muted-foreground">Conector desconocido para «{source.type}».</p>
                <Button variant="ghost" size="sm" className="ir-mt-2" onClick={onClose}>
                    Cerrar
                </Button>
            </div>
        );
    }

    const save = (event: FormEvent): void => {
        event.preventDefault();
        const { config, credentials } = splitConfig(connector, values);
        update.mutate({ id: source.id, config, credentials }, { onSuccess: onClose });
    };

    return (
        <form onSubmit={save} className="ir-mt-2 ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
            <p className="ir-text-xs ir-text-muted-foreground">Actualiza la URL, claves o el token si caducó. Los campos secretos en blanco se conservan.</p>
            <SetupGuidePanel connector={connector} />
            <ConnectorFields connector={connector} values={values} editing onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))} />
            {update.isError && (
                <p className="ir-rounded ir-bg-danger/10 ir-px-2.5 ir-py-1.5 ir-text-xs ir-text-danger">{apiErrorMessage(update.error, 'No se pudo guardar la fuente. Revisa las credenciales.')}</p>
            )}
            <div className="ir-flex ir-gap-2">
                <Button type="submit" size="sm" disabled={update.isPending}>
                    Guardar cambios
                </Button>
                <Button type="button" size="sm" variant="ghost" onClick={onClose}>
                    Cancelar
                </Button>
            </div>
        </form>
    );
}

/**
 * What this source actually points at.
 *
 * A source whose account was auto-selected (the single-option case) showed nothing at all:
 * no dropdown, because there was nothing to choose, and no trace of what had been chosen —
 * so "detected one property and used it" and "detected nothing" looked identical. This puts
 * the configured, non-secret identifiers on the row, where they stay after a reload.
 */
function ConfiguredAccount({ source, connector }: { source: DataSourceDto; connector?: Connector }): ReactElement | null {
    const config = source.config;
    if (connector === undefined || config === null) {
        return null;
    }

    const shown = connector.config_schema
        .filter((field) => !field.secret)
        .map((field) => ({ label: field.label, value: config[field.key] }))
        .filter((entry): entry is { label: string; value: string } => typeof entry.value === 'string' && entry.value !== '');

    if (shown.length === 0) {
        return null;
    }

    return (
        <p className="ir-mt-1 ir-flex ir-flex-wrap ir-gap-x-3 ir-gap-y-0.5 ir-text-[11px] ir-text-muted-foreground">
            {shown.map((entry) => (
                <span key={entry.label}>
                    {entry.label}: <span className="ir-font-medium ir-text-foreground">{entry.value}</span>
                </span>
            ))}
        </p>
    );
}

/**
 * "Pick your property/account" step shown after a one-click OAuth connect returns multiple
 * resources (GA4 properties, ad accounts…). Saving fills the config field and clears the picker.
 */
function ResourcePicker({ source, siteId }: { source: DataSourceDto; siteId: number }): ReactElement | null {
    const options = source.connect_options;
    const update = useUpdateDataSource(siteId);
    const [value, setValue] = useState('');

    if (options == null || options.options.length === 0) {
        return null;
    }

    const save = (): void => {
        if (value === '') {
            return;
        }
        const config: Record<string, string> = {};
        for (const [key, current] of Object.entries(source.config ?? {})) {
            if (typeof current === 'string') {
                config[key] = current;
            }
        }
        config[options.field] = value;
        update.mutate({ id: source.id, config });
    };

    return (
        <div className="ir-mt-2 ir-flex ir-flex-col ir-gap-2 ir-rounded-md ir-border ir-border-primary/30 ir-bg-primary/5 ir-p-3">
            <p className="ir-text-xs ir-font-medium">✅ Cuenta conectada. Elige {options.label}:</p>
            <div className="ir-flex ir-gap-2">
                <select
                    className="ir-h-9 ir-w-full ir-rounded-md ir-border ir-bg-card ir-px-3 ir-text-sm"
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                >
                    <option value="">Selecciona…</option>
                    {options.options.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <Button type="button" size="sm" onClick={save} disabled={value === '' || update.isPending}>
                    Guardar
                </Button>
            </div>
        </div>
    );
}

/** Colored status dot for a data source (green = ok, red = error, amber = partial). */
function syncedAgo(iso: string | null | undefined): string {
    if (iso == null) {
        return '';
    }
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 60) {
        return ` · sincronizada hace ${Math.max(1, minutes)} min`;
    }
    const hours = Math.round(minutes / 60);

    return hours < 48 ? ` · sincronizada hace ${hours} h` : ` · sincronizada hace ${Math.round(hours / 24)} días`;
}


/**
 * Site Agent version line: shows the plugin version the site reports and, when it's
 * behind the shipped one, an amber prompt to update — an outdated agent silently omits
 * newer metrics (e.g. the applied-updates history), which otherwise looks "broken".
 */
function AgentVersion({ source }: { source: DataSourceDto }): ReactElement | null {
    if (source.type !== 'site_agent' || !source.agent_version) {
        return null;
    }

    if (source.agent_outdated) {
        return (
            <p className="ir-truncate ir-text-xs ir-text-amber-600" title={`El sitio corre el agente ${source.agent_version}. Sube el plugin a ${source.agent_latest} para recuperar las métricas nuevas (p. ej. el historial de actualizaciones).`}>
                ⚠ Agente {source.agent_version} — actualiza a {source.agent_latest}
            </p>
        );
    }

    return <p className="ir-truncate ir-text-xs ir-text-muted-foreground">Agente {source.agent_version} ✓</p>;
}

/**
 * What the provider's redirect brought back (`?connected=` / `?connect_error=`), read once and
 * then removed from the address bar so a reload doesn't show it again. Before this the panel
 * ignored both, so a failed «Conectar con Google» just landed silently on the same page.
 */
function takeConnectResult(): { ok: boolean; message: string; type: string } | null {
    const params = new URLSearchParams(window.location.search);
    const error = params.get('connect_error');
    const connected = params.get('connected');
    if (error === null && connected === null) {
        return null;
    }

    params.delete('connect_error');
    params.delete('connected');
    params.delete('source');
    const query = params.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query !== '' ? `?${query}` : ''}${window.location.hash}`);

    return error !== null ? { ok: false, message: error, type: '' } : { ok: true, message: '', type: connected ?? '' };
}

/**
 * Self-contained data-sources manager for a single site: lists the configured connectors
 * with test/edit/delete, and an "add source" panel driven by each connector's
 * configSchema. Extracted so the workspace (master-detail) can embed it directly.
 */
export function SiteDataSources({ siteId }: { siteId: number }): ReactElement {
    const { data: connectors = [] } = useConnectors();
    const { data: sources = [] } = useSiteDataSources(siteId);
    const create = useCreateDataSource(siteId);
    const remove = useDeleteDataSource(siteId);
    const test = useTestConnection();
    const discover = useDiscoverResources(siteId);
    const connectStart = useConnectStart(siteId);

    const [adding, setAdding] = useState(false);
    const [type, setType] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [results, setResults] = useState<Record<number, string>>({});
    const [editing, setEditing] = useState<number | null>(null);
    const [builderFor, setBuilderFor] = useState<DataSourceDto | null>(null);
    // When a connector supports one-click connect, the manual form is hidden behind a toggle
    // ("usar mis propios accesos") so the client can still bring their own tokens if they prefer.
    const [manualOpen, setManualOpen] = useState(false);
    const [connectResult, setConnectResult] = useState(takeConnectResult);

    const connector = connectors.find((item) => item.key === type);
    const labelFor = (sourceType: string): string => connectors.find((item) => item.key === sourceType)?.label ?? SOURCE_META[sourceType]?.label ?? sourceType;

    // Missing any required up-front connect field (e.g. the WooCommerce store URL)?
    const connectReady =
        connector?.connect != null &&
        connector.connect.fields.every((field) => !field.required || (values[field.key] ?? '').trim() !== '');

    const startConnect = (): void => {
        if (connector?.connect == null) {
            return;
        }
        const input: Record<string, string> = {};
        for (const field of connector.connect.fields) {
            input[field.key] = (values[field.key] ?? '').trim();
        }
        connectStart.mutate(
            { type: connector.key, input, returnUrl: window.location.href },
            { onSuccess: (result) => (window.location.href = result.redirect_url) },
        );
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (connector === undefined) {
            return;
        }
        const { config, credentials } = splitConfig(connector, values);
        create.mutate(
            { type: connector.key, config, credentials },
            {
                onSuccess: () => {
                    setValues({});
                    setType('');
                    setAdding(false);
                },
            },
        );
    };

    const runTest = (id: number): void => {
        test.mutate(id, { onSuccess: (result) => setResults((prev) => ({ ...prev, [id]: result.message })) });
    };

    const runDiscover = (id: number): void => {
        discover.mutate(id, { onSuccess: (result) => setResults((prev) => ({ ...prev, [id]: result.message })) });
    };

    const confirmRemove = (source: DataSourceDto): void => {
        if (window.confirm(`¿Eliminar la fuente «${labelFor(source.type)}»? Se borrará también su historial sincronizado.`)) {
            remove.mutate(source.id, { onSuccess: () => setEditing((current) => (current === source.id ? null : current)) });
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            {connectResult !== null && (
                <div
                    role="status"
                    className={`ir-flex ir-items-start ir-justify-between ir-gap-3 ir-rounded-md ir-p-3 ir-text-sm ${
                        connectResult.ok ? 'ir-bg-success/10 ir-text-success' : 'ir-bg-danger/10 ir-text-danger'
                    }`}
                >
                    <p>
                        {connectResult.ok
                            ? `${labelFor(connectResult.type)} quedó conectado. Revisa abajo qué cuenta se eligió o elígela en el desplegable, y pulsa «Probar».`
                            : `No se pudo conectar: ${connectResult.message}`}
                    </p>
                    <button type="button" className="ir-shrink-0 ir-text-xs ir-underline" onClick={() => setConnectResult(null)}>
                        Cerrar
                    </button>
                </div>
            )}
            <div className="ir-flex ir-items-center ir-justify-between ir-gap-3">
                <div>
                    <h3 className="ir-flex ir-items-center ir-gap-2 ir-text-[15px] ir-font-semibold ir-tracking-tight">
                        Fuentes de datos
                        <span className="ir-rounded-full ir-bg-muted ir-px-1.5 ir-py-0.5 ir-text-[11px] ir-font-medium ir-text-muted-foreground">{sources.length}</span>
                    </h3>
                    <p className="ir-text-[13px] ir-text-muted-foreground">De aquí salen los números del reporte. Solo lectura: nunca se modifica nada en las cuentas.</p>
                </div>
                <Button
                    size="sm"
                    variant={adding ? 'ghost' : 'primary'}
                    onClick={() => {
                        setAdding((open) => !open);
                        setType('');
                        setValues({});
                        setManualOpen(false);
                    }}
                >
                    {adding ? 'Cancelar' : <><Plus className="ir-size-3.5" />Añadir fuente</>}
                </Button>
            </div>

            {adding && (
                <div className="ir-flex ir-flex-col ir-gap-4 ir-rounded-xl ir-border ir-bg-muted/30 ir-p-4">
                    {connector === undefined ? (
                        <div className="ir-flex ir-flex-col ir-gap-4">
                            <p className="ir-text-sm ir-font-medium">¿Qué quieres conectar?</p>
                            {SOURCE_CATEGORIES.map((category) => {
                                const options = connectors.filter((item) => (SOURCE_META[item.key]?.category ?? 'custom') === category.key);
                                if (options.length === 0) {
                                    return null;
                                }

                                return (
                                    <div key={category.key}>
                                        <p className="ir-mb-2 ir-text-[11px] ir-font-medium ir-uppercase ir-tracking-wider ir-text-muted-foreground">{category.label}</p>
                                        <div className="ir-grid ir-gap-2 sm:ir-grid-cols-2 xl:ir-grid-cols-3">
                                            {options.map((item) => {
                                                const meta = SOURCE_META[item.key];
                                                const Icon = meta?.icon ?? Sparkles;
                                                const already = sources.some((source) => source.type === item.key);

                                                return (
                                                    <button
                                                        key={item.key}
                                                        type="button"
                                                        onClick={() => {
                                                            setType(item.key);
                                                            setValues({});
                                                            setManualOpen(false);
                                                        }}
                                                        className="ir-flex ir-items-center ir-gap-3 ir-rounded-lg ir-border ir-bg-card ir-p-2.5 ir-text-left ir-transition hover:ir-border-foreground/25 hover:ir-shadow-ir-xs"
                                                    >
                                                        <span className="ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-muted ir-text-foreground/80">
                                                            <Icon className="ir-size-4" />
                                                        </span>
                                                        <span className="ir-min-w-0">
                                                            <span className="ir-block ir-truncate ir-text-sm ir-font-medium">{item.label}</span>
                                                            <span className="ir-block ir-truncate ir-text-xs ir-text-muted-foreground">
                                                                {already ? 'Ya conectada' : meta?.blurb ?? (item.connect != null ? 'Conexión con un clic' : 'Con claves de acceso')}
                                                            </span>
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="ir-flex ir-items-center ir-gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    setType('');
                                    setValues({});
                                    setManualOpen(false);
                                }}
                                className="ir-flex ir-size-7 ir-items-center ir-justify-center ir-rounded-lg ir-text-muted-foreground hover:ir-bg-card hover:ir-text-foreground"
                                title="Elegir otra fuente"
                            >
                                <ChevronLeft className="ir-size-4" />
                            </button>
                            <p className="ir-text-sm ir-font-semibold">Conectar {connector.label}</p>
                        </div>
                    )}

                    {/* One-click connect (the client authorizes on the provider's own screen). */}
                    {connector?.connect != null && (
                        <div className="ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-border-primary/30 ir-bg-primary/5 ir-p-3">
                            <p className="ir-text-xs ir-text-muted-foreground">
                                Conexión rápida: tu cliente autoriza el acceso de solo lectura en su propia cuenta, sin copiar claves ni tokens.
                            </p>
                            <FieldList
                                fields={connector.connect.fields}
                                values={values}
                                onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))}
                            />
                            {connectStart.isError && (
                                <p className="ir-rounded ir-bg-danger/10 ir-px-2.5 ir-py-1.5 ir-text-xs ir-text-danger">{apiErrorMessage(connectStart.error, 'No se pudo iniciar la conexión. Revisa la URL e inténtalo de nuevo.')}</p>
                            )}
                            <Button type="button" size="sm" onClick={startConnect} disabled={!connectReady || connectStart.isPending}>
                                {connectStart.isPending ? 'Redirigiendo…' : connector.connect.label}
                            </Button>
                            <button
                                type="button"
                                className="ir-self-start ir-text-xs ir-text-muted-foreground ir-underline hover:ir-text-foreground"
                                onClick={() => setManualOpen((open) => !open)}
                            >
                                {manualOpen ? 'Ocultar la opción manual' : 'o usar mis propios accesos (avanzado)'}
                            </button>
                        </div>
                    )}

                    {/* Manual form (always available; hidden behind a toggle when connect exists). */}
                    {connector !== undefined && (connector.connect == null || manualOpen) && (
                        <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                            <SetupGuidePanel connector={connector} />
                            <ConnectorFields connector={connector} values={values} onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))} />
                            {create.isError && (
                                <p className="ir-rounded ir-bg-danger/10 ir-px-2.5 ir-py-1.5 ir-text-xs ir-text-danger">{apiErrorMessage(create.error, 'No se pudo guardar la fuente. Revisa las credenciales e inténtalo de nuevo.')}</p>
                            )}
                            <Button type="submit" size="sm" disabled={create.isPending}>
                                Guardar fuente
                            </Button>
                        </form>
                    )}
                </div>
            )}

            <ul className="ir-flex ir-flex-col ir-gap-2">
                {sources.map((source) => {
                    // Anything that can list its accounts, not just the OAuth ones: a plain
                    // API key reaches an account list too, and picking from a dropdown beats
                    // hunting for a numeric id in the provider's UI.
                    const canDiscover = connectors.find((item) => item.key === source.type)?.lists_resources === true;

                    const meta = SOURCE_META[source.type];
                    const Icon = meta?.icon ?? Sparkles;
                    const tone = source.status === 'ok' ? 'success' : source.status === 'error' || source.status === 'failed' ? 'danger' : source.status === 'pending' || source.status === 'partial' ? 'warning' : 'neutral';
                    const fallback = source.status === 'ok' ? `Funcionando${syncedAgo(source.last_synced_at)}` : source.status === 'pending' ? 'Pendiente: pulsa «Probar» para comprobarla' : source.status;
                    const message = results[source.id] ?? source.last_error ?? fallback;

                    return (
                        <li
                            key={source.id}
                            className={'ir-rounded-xl ir-border ir-bg-card ir-px-3.5 ir-py-3 ir-transition-colors hover:ir-border-foreground/15 ' + (tone === 'danger' ? 'ir-border-danger/30 ir-bg-danger/[0.02]' : '')}
                        >
                            <div className="ir-flex ir-items-center ir-justify-between ir-gap-3">
                                <div className="ir-flex ir-min-w-0 ir-items-center ir-gap-3">
                                    <span className="ir-relative ir-flex ir-size-9 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-lg ir-bg-muted ir-text-foreground/80">
                                        <Icon className="ir-size-4" />
                                        <StatusDot tone={tone} className="ir-absolute -ir-bottom-0.5 -ir-right-0.5 ir-ring-2 ir-ring-card ir-rounded-full" />
                                    </span>
                                    <div className="ir-min-w-0">
                                        <p className="ir-truncate ir-text-sm ir-font-medium">{labelFor(source.type)}</p>
                                        {/* Wraps rather than truncates: this line carries the reason a
                                            connection failed, which is useless cut off at one line. */}
                                        <p className={'ir-text-xs ' + (tone === 'danger' ? 'ir-text-danger' : 'ir-text-muted-foreground')}>{message}</p>
                                        <AgentVersion source={source} />
                                    </div>
                                </div>
                                <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                                    {source.is_push !== true && (
                                        <Button variant="ghost" size="sm" onClick={() => runTest(source.id)} disabled={test.isPending} title="Comprueba que la conexión funciona">
                                            <RefreshCw className={'ir-size-3.5 ' + (test.isPending && test.variables === source.id ? 'ir-animate-spin' : '')} />
                                            <span className="ir-hidden sm:ir-inline">Probar</span>
                                        </Button>
                                    )}
                                    <ActionMenu
                                        label={`Acciones de ${labelFor(source.type)}`}
                                        items={[
                                            canDiscover && { label: 'Detectar cuentas', icon: Search, hint: 'Vuelve a listar las cuentas que ve', onSelect: () => runDiscover(source.id), disabled: discover.isPending },
                                            source.type === 'ga4' && { label: 'Métricas personalizadas', icon: FlaskConical, onSelect: () => setBuilderFor(source) },
                                            { label: editing === source.id ? 'Cerrar edición' : 'Editar configuración', icon: Pencil, onSelect: () => setEditing((current) => (current === source.id ? null : source.id)) },
                                            { label: 'Eliminar fuente', icon: Trash2, danger: true, onSelect: () => confirmRemove(source), disabled: remove.isPending },
                                        ]}
                                    />
                                </div>
                            </div>
                            <ConfiguredAccount source={source} connector={connectors.find((item) => item.key === source.type)} />
                            <ResourcePicker source={source} siteId={siteId} />
                            <PushInstallPanel source={source} />
                            {editing === source.id && (
                                <DataSourceEditForm
                                    source={source}
                                    connector={connectors.find((item) => item.key === source.type)}
                                    siteId={siteId}
                                    onClose={() => setEditing(null)}
                                />
                            )}
                        </li>
                    );
                })}
                {sources.length === 0 && !adding && (
                    <li className="ir-rounded-xl ir-border ir-border-dashed ir-p-8 ir-text-center ir-text-sm ir-text-muted-foreground">
                        Este sitio aún no tiene fuentes. Pulsa «Añadir fuente» y conecta Google Analytics, Search Console, MainWP… Sin fuentes, el reporte sale vacío.
                    </li>
                )}
            </ul>

            {/* Period-sync tools live BELOW the list and collapsed by default, so the sources
                (the primary content) stay at the top and the panel no longer dominates. */}
            {sources.length > 0 && <RangeSyncMenu siteId={siteId} />}

            {builderFor !== null && (
                <Ga4DatasetBuilder
                    dataSourceId={builderFor.id}
                    initialDatasets={(builderFor.config?.custom_datasets as Ga4DatasetSpec[] | undefined) ?? []}
                    onClose={() => setBuilderFor(null)}
                />
            )}
        </div>
    );
}
