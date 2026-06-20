import { zodResolver } from '@hookform/resolvers/zod';
import { type ColumnDef } from '@tanstack/react-table';
import { type ReactElement } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useClients, useCreateSite, useSites } from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import { CURRENCIES } from '../currencies';
import { useAdminUi } from '../store';
import type { Site } from '../types';

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
                <Button variant="ghost" onClick={() => selectSite(row.original.id)}>
                    Fuentes de datos
                </Button>
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

            <Card title="Sitios">
                <DataTable columns={columns} data={sites} />
            </Card>
        </div>
    );
}
