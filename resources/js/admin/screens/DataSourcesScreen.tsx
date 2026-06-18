import { type FormEvent, type ReactElement, useState } from 'react';

import { useConnectors, useCreateDataSource, useSiteDataSources, useTestConnection } from '../api';
import { Button, Card, Field, Input } from '../components/ui';
import { useAdminUi } from '../store';

export function DataSourcesScreen(): ReactElement {
    const siteId = useAdminUi((state) => state.selectedSiteId);
    const { data: connectors = [] } = useConnectors();
    const { data: sources = [] } = useSiteDataSources(siteId);
    const create = useCreateDataSource(siteId ?? 0);
    const test = useTestConnection();

    const [type, setType] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [results, setResults] = useState<Record<number, string>>({});

    if (siteId === null) {
        return <Card>Selecciona un sitio en la pestaña «Sitios» para configurar sus fuentes de datos.</Card>;
    }

    const connector = connectors.find((item) => item.key === type);

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

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
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

                    {connector?.config_schema.map((field) => (
                        <Field key={field.key} label={field.label}>
                            <Input
                                type={field.secret ? 'password' : 'text'}
                                value={values[field.key] ?? ''}
                                onChange={(event) =>
                                    setValues((prev) => ({ ...prev, [field.key]: event.target.value }))
                                }
                            />
                        </Field>
                    ))}

                    {connector !== undefined && (
                        <Button type="submit" disabled={create.isPending}>
                            Guardar fuente
                        </Button>
                    )}
                </form>
            </Card>

            <Card title="Fuentes configuradas">
                <ul className="ir-flex ir-flex-col ir-gap-3">
                    {sources.map((source) => (
                        <li key={source.id} className="ir-flex ir-items-center ir-justify-between ir-border-t ir-pt-3">
                            <div>
                                <p className="ir-font-medium">{source.type}</p>
                                <p className="ir-text-xs ir-text-muted-foreground">
                                    {source.status}
                                    {results[source.id] !== undefined ? ` — ${results[source.id]}` : ''}
                                </p>
                            </div>
                            <Button variant="ghost" onClick={() => runTest(source.id)} disabled={test.isPending}>
                                Probar conexión
                            </Button>
                        </li>
                    ))}
                    {sources.length === 0 && <li className="ir-text-sm ir-text-muted-foreground">Sin fuentes todavía.</li>}
                </ul>
            </Card>
        </div>
    );
}
