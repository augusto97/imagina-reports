import { type FormEvent, type ReactElement, useState } from 'react';

import {
    useConnectors,
    useCreateDataSource,
    useDeleteDataSource,
    useSiteDataSources,
    useSites,
    useTestConnection,
    useUpdateDataSource,
} from '../api';
import { Button, Card, Field, Select } from '../components/ui';
import { Input } from '../components/ui';
import { useAdminUi } from '../store';
import type { Connector, DataSourceDto } from '../types';

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
            // Non-secret config comes back from the API; secrets never do (kept if blank).
            if (!field.secret) {
                const current = (source.config ?? {})[field.key];
                initial[field.key] = typeof current === 'string' ? current : '';
            }
        }
        return initial;
    });

    if (connector === undefined) {
        return (
            <Card title="Editar fuente">
                <p className="ir-text-sm ir-text-muted-foreground">Conector desconocido para «{source.type}».</p>
                <Button variant="ghost" onClick={onClose}>
                    Cerrar
                </Button>
            </Card>
        );
    }

    const save = (event: FormEvent): void => {
        event.preventDefault();
        const config: Record<string, string> = {};
        const credentials: Record<string, string> = {};
        for (const field of connector.config_schema) {
            const value = values[field.key] ?? '';
            if (field.secret) {
                credentials[field.key] = value; // blank = keep existing (handled by API)
            } else {
                config[field.key] = value;
            }
        }
        update.mutate({ id: source.id, config, credentials }, { onSuccess: onClose });
    };

    return (
        <Card title={`Editar «${connector.label}»`} description="Actualiza la URL, claves o el token si caducó. Los campos secretos en blanco se conservan.">
            <form onSubmit={save} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                <SetupGuidePanel connector={connector} />
                {connector.config_schema.map((field) => (
                    <Field key={field.key} label={field.label}>
                        <Input
                            type={field.secret ? 'password' : 'text'}
                            value={values[field.key] ?? ''}
                            placeholder={field.secret ? 'Déjalo vacío para conservar el actual' : undefined}
                            onChange={(event) => setValues((prev) => ({ ...prev, [field.key]: event.target.value }))}
                        />
                        {field.help !== null && <span className="ir-text-xs ir-text-muted-foreground">{field.help}</span>}
                    </Field>
                ))}
                <div className="ir-flex ir-gap-2">
                    <Button type="submit" disabled={update.isPending}>
                        Guardar cambios
                    </Button>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                </div>
            </form>
        </Card>
    );
}

export function DataSourcesScreen(): ReactElement {
    const siteId = useAdminUi((state) => state.selectedSiteId);
    const selectSite = useAdminUi((state) => state.selectSite);
    const { data: sites = [] } = useSites();
    const { data: connectors = [] } = useConnectors();
    const { data: sources = [] } = useSiteDataSources(siteId);
    const create = useCreateDataSource(siteId ?? 0);
    const remove = useDeleteDataSource(siteId ?? 0);
    const test = useTestConnection();

    const [type, setType] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [results, setResults] = useState<Record<number, string>>({});
    const [editing, setEditing] = useState<DataSourceDto | null>(null);

    const sitePicker = (
        <Card title="Sitio" description="Elige el sitio cuyas fuentes de datos quieres configurar.">
            <Field label="Sitio">
                <Select value={siteId ?? ''} onChange={(event) => event.target.value !== '' && selectSite(Number(event.target.value))}>
                    <option value="">Selecciona un sitio…</option>
                    {sites.map((site) => (
                        <option key={site.id} value={site.id}>
                            {site.name}
                        </option>
                    ))}
                </Select>
            </Field>
        </Card>
    );

    if (siteId === null) {
        return (
            <div className="ir-flex ir-flex-col ir-gap-6">
                {sitePicker}
                <p className="ir-text-sm ir-text-muted-foreground">
                    Selecciona un sitio arriba para añadir y probar sus conectores (GA4, MainWP, WooCommerce…).
                </p>
            </div>
        );
    }

    const connector = connectors.find((item) => item.key === type);
    const labelFor = (sourceType: string): string => connectors.find((item) => item.key === sourceType)?.label ?? sourceType;

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (connector === undefined) {
            return;
        }

        const config: Record<string, string> = {};
        const credentials: Record<string, string> = {};
        for (const field of connector.config_schema) {
            const value = values[field.key] ?? '';
            if (field.secret) {
                credentials[field.key] = value;
            } else {
                config[field.key] = value;
            }
        }

        create.mutate(
            { type, config, credentials },
            {
                onSuccess: () => {
                    setValues({});
                    setType('');
                },
            },
        );
    };

    const runTest = (id: number): void => {
        test.mutate(id, {
            onSuccess: (result) => setResults((prev) => ({ ...prev, [id]: result.message })),
        });
    };

    const confirmRemove = (source: DataSourceDto): void => {
        if (window.confirm(`¿Eliminar la fuente «${labelFor(source.type)}»? Se borrará también su historial sincronizado.`)) {
            remove.mutate(source.id, { onSuccess: () => setEditing((current) => (current?.id === source.id ? null : current)) });
        }
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            {sitePicker}
            <Card title="Añadir fuente de datos">
                <form onSubmit={submit} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                    <Field label="Conector">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
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

                    {connector?.config_schema.map((field) => (
                        <Field key={field.key} label={field.label}>
                            <Input
                                type={field.secret ? 'password' : 'text'}
                                value={values[field.key] ?? ''}
                                onChange={(event) =>
                                    setValues((prev) => ({ ...prev, [field.key]: event.target.value }))
                                }
                            />
                            {field.help !== null && (
                                <span className="ir-text-xs ir-text-muted-foreground">{field.help}</span>
                            )}
                        </Field>
                    ))}

                    {connector !== undefined && (
                        <Button type="submit" disabled={create.isPending}>
                            Guardar fuente
                        </Button>
                    )}
                </form>
            </Card>

            {editing !== null && (
                <DataSourceEditForm
                    key={editing.id}
                    source={editing}
                    connector={connectors.find((item) => item.key === editing.type)}
                    siteId={siteId}
                    onClose={() => setEditing(null)}
                />
            )}

            <Card title="Fuentes configuradas">
                <ul className="ir-flex ir-flex-col ir-gap-3">
                    {sources.map((source) => (
                        <li key={source.id} className="ir-flex ir-flex-col ir-gap-2 ir-border-t ir-pt-3">
                            <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                                <div className="ir-min-w-0">
                                    <p className="ir-font-medium">{labelFor(source.type)}</p>
                                    <p className="ir-text-xs ir-text-muted-foreground">
                                        {source.status}
                                        {results[source.id] !== undefined ? ` — ${results[source.id]}` : ''}
                                        {source.last_error !== null && results[source.id] === undefined ? ` — ${source.last_error}` : ''}
                                    </p>
                                </div>
                                <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-1">
                                    {source.is_push !== true && (
                                        <Button variant="ghost" onClick={() => runTest(source.id)} disabled={test.isPending}>
                                            Probar
                                        </Button>
                                    )}
                                    <Button variant="ghost" onClick={() => setEditing(source)}>
                                        Editar
                                    </Button>
                                    <Button variant="ghost" onClick={() => confirmRemove(source)} disabled={remove.isPending}>
                                        Eliminar
                                    </Button>
                                </div>
                            </div>
                            <PushInstallPanel source={source} />
                        </li>
                    ))}
                    {sources.length === 0 && <li className="ir-text-sm ir-text-muted-foreground">Sin fuentes todavía.</li>}
                </ul>
            </Card>
        </div>
    );
}
