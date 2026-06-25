import { type FormEvent, type ReactElement, useState } from 'react';

import {
    downloadSiteAgentPlugin,
    useConnectors,
    useCreateDataSource,
    useDeleteDataSource,
    useSiteDataSources,
    useTestConnection,
    useUpdateDataSource,
} from '../api';
import type { Connector, DataSourceDto } from '../types';
import { Badge, Button, Field, Input } from './ui';

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

function statusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
    if (status === 'ok') return 'success';
    if (status === 'error' || status === 'failed') return 'danger';
    if (status === 'partial' || status === 'pending') return 'warning';
    return 'neutral';
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

    const [adding, setAdding] = useState(false);
    const [type, setType] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [results, setResults] = useState<Record<number, string>>({});
    const [editing, setEditing] = useState<number | null>(null);

    const connector = connectors.find((item) => item.key === type);
    const labelFor = (sourceType: string): string => connectors.find((item) => item.key === sourceType)?.label ?? sourceType;

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

    const confirmRemove = (source: DataSourceDto): void => {
        if (window.confirm(`¿Eliminar la fuente «${labelFor(source.type)}»? Se borrará también su historial sincronizado.`)) {
            remove.mutate(source.id, { onSuccess: () => setEditing((current) => (current === source.id ? null : current)) });
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-3">
            <div className="ir-flex ir-items-center ir-justify-between">
                <h3 className="ir-text-sm ir-font-semibold">Fuentes de datos ({sources.length})</h3>
                <Button
                    size="sm"
                    variant={adding ? 'ghost' : 'primary'}
                    onClick={() => {
                        setAdding((open) => !open);
                        setType('');
                        setValues({});
                    }}
                >
                    {adding ? 'Cerrar' : '+ Añadir fuente'}
                </Button>
            </div>

            {adding && (
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3 ir-rounded-md ir-border ir-bg-muted/20 ir-p-3">
                    <Field label="Conector">
                        <select
                            className="ir-h-9 ir-w-full ir-rounded-md ir-border ir-bg-card ir-px-3 ir-text-sm"
                            value={type}
                            onChange={(event) => {
                                setType(event.target.value);
                                setValues({});
                            }}
                        >
                            <option value="">Selecciona…</option>
                            {connectors.map((item) => (
                                <option key={item.key} value={item.key}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    {connector !== undefined && <SetupGuidePanel connector={connector} />}
                    {connector !== undefined && (
                        <ConnectorFields connector={connector} values={values} onChange={(key, value) => setValues((prev) => ({ ...prev, [key]: value }))} />
                    )}

                    {connector !== undefined && (
                        <Button type="submit" size="sm" disabled={create.isPending}>
                            Guardar fuente
                        </Button>
                    )}
                </form>
            )}

            <ul className="ir-flex ir-flex-col ir-gap-2">
                {sources.map((source) => (
                    <li key={source.id} className="ir-rounded-md ir-border ir-p-3">
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                            <div className="ir-min-w-0">
                                <div className="ir-flex ir-items-center ir-gap-2">
                                    <span className="ir-font-medium">{labelFor(source.type)}</span>
                                    <Badge tone={statusTone(source.status)}>{source.status}</Badge>
                                </div>
                                {(results[source.id] !== undefined || source.last_error !== null) && (
                                    <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                        {results[source.id] ?? source.last_error}
                                    </p>
                                )}
                            </div>
                            <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                                {source.is_push !== true && (
                                    <Button variant="ghost" size="sm" onClick={() => runTest(source.id)} disabled={test.isPending}>
                                        Probar
                                    </Button>
                                )}
                                <Button variant="ghost" size="sm" onClick={() => setEditing((current) => (current === source.id ? null : source.id))}>
                                    {editing === source.id ? 'Cerrar' : 'Editar'}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => confirmRemove(source)} disabled={remove.isPending}>
                                    Eliminar
                                </Button>
                            </div>
                        </div>
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
                ))}
                {sources.length === 0 && !adding && (
                    <li className="ir-rounded-md ir-border ir-border-dashed ir-p-4 ir-text-center ir-text-sm ir-text-muted-foreground">
                        Aún no hay fuentes. Pulsa «+ Añadir fuente» para conectar GA4, MainWP, el Agente del sitio, WooCommerce…
                    </li>
                )}
            </ul>
        </div>
    );
}
