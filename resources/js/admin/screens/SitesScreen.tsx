import { zodResolver } from '@hookform/resolvers/zod';
import { type ColumnDef } from '@tanstack/react-table';
import { type ReactElement, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useClients, useCreateSite, useSites, useUpdateSite } from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import { CURRENCIES } from '../currencies';
import { useAdminUi } from '../store';
import type { Site } from '../types';

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

/** Inline edit form for an existing site (name, URL, currency). */
function SiteEditForm({ site, onClose }: { site: Site; onClose: () => void }): ReactElement {
    const update = useUpdateSite(site.id);
    const [name, setName] = useState(site.name);
    const [url, setUrl] = useState(site.url);
    const [currency, setCurrency] = useState(site.currency);
    const [planHours, setPlanHours] = useState(site.plan_hours ?? '');

    const save = (): void => {
        update.mutate({ name, url, currency, plan_hours: planHours === '' ? null : Number(planHours) }, { onSuccess: onClose });
    };

    return (
        <Card title={`Editar «${site.name}»`}>
            <div className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                <Field label="Nombre">
                    <Input value={name} onChange={(event) => setName(event.target.value)} />
                </Field>
                <Field label="URL">
                    <Input value={url} onChange={(event) => setUrl(event.target.value)} />
                </Field>
                <Field label="Moneda del sitio">
                    <select className={selectClass} value={currency} onChange={(event) => setCurrency(event.target.value)}>
                        {CURRENCIES.map((option) => (
                            <option key={option.code} value={option.code}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Horas de plan/mes (opcional)">
                    <Input type="number" min="0" step="0.5" value={planHours} onChange={(event) => setPlanHours(event.target.value)} placeholder="Ej. 5" />
                </Field>
                <div className="ir-flex ir-gap-2">
                    <Button onClick={save} disabled={update.isPending}>
                        Guardar cambios
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Cancelar
                    </Button>
                </div>
            </div>
        </Card>
    );
}

const schema = z.object({
    client_id: z.coerce.number().int().positive('Selecciona un cliente'),
    name: z.string().min(1, 'Requerido'),
    url: z.string().url('URL inválida'),
    currency: z.string().min(3),
});

type Values = z.infer<typeof schema>;

export function SitesScreen(): ReactElement {
    const { data: sites = [] } = useSites();
    const { data: clients = [] } = useClients();
    const create = useCreateSite();
    const selectSite = useAdminUi((state) => state.selectSite);
    const [editingSite, setEditingSite] = useState<Site | null>(null);

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: '', url: '', currency: 'USD' } });

    const onSubmit = handleSubmit((values) => {
        create.mutate(values, { onSuccess: () => reset() });
    });

    const columns: ColumnDef<Site>[] = [
        { header: 'Nombre', accessorKey: 'name' },
        { header: 'URL', accessorKey: 'url' },
        { header: 'Moneda', accessorKey: 'currency' },
        { header: 'Estado', accessorKey: 'status' },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => (
                <div className="ir-flex ir-items-center ir-gap-2">
                    <Button variant="ghost" onClick={() => setEditingSite(row.original)}>
                        Editar
                    </Button>
                    <Button variant="ghost" onClick={() => selectSite(row.original.id)}>
                        Fuentes de datos
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Nuevo sitio">
                <form onSubmit={onSubmit} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                    <Field label="Cliente" error={errors.client_id?.message}>
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            {...register('client_id')}
                        >
                            <option value="">Selecciona…</option>
                            {clients.map((client) => (
                                <option key={client.id} value={client.id}>
                                    {client.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Nombre" error={errors.name?.message}>
                        <Input {...register('name')} />
                    </Field>
                    <Field label="URL" error={errors.url?.message}>
                        <Input placeholder="https://…" {...register('url')} />
                    </Field>
                    <Field label="Moneda del sitio" error={errors.currency?.message}>
                        <select className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm" {...register('currency')}>
                            {CURRENCIES.map((currency) => (
                                <option key={currency.code} value={currency.code}>
                                    {currency.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Button type="submit" disabled={create.isPending}>
                        Crear sitio
                    </Button>
                </form>
            </Card>

            {editingSite !== null && <SiteEditForm key={editingSite.id} site={editingSite} onClose={() => setEditingSite(null)} />}

            <Card title="Sitios">
                <DataTable columns={columns} data={sites} />
            </Card>
        </div>
    );
}
