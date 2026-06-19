import { zodResolver } from '@hookform/resolvers/zod';
import { type ColumnDef } from '@tanstack/react-table';
import { type ReactElement } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useClients, useCreateClient } from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import type { Client } from '../types';

const schema = z.object({
    name: z.string().min(1, 'Requerido'),
    contact_email: z.string().email('Email inválido').optional().or(z.literal('')),
});

type Values = z.infer<typeof schema>;

const columns: ColumnDef<Client>[] = [
    { header: 'Nombre', accessorKey: 'name' },
    { header: 'Email', accessorKey: 'contact_email' },
    { header: 'Locale', accessorKey: 'locale' },
];

export function ClientsScreen(): ReactElement {
    const { data = [] } = useClients();
    const create = useCreateClient();
    const {
        register,
        handleSubmit,
        reset,
        formState: { errors },
    } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: '', contact_email: '' } });

    const onSubmit = handleSubmit((values) => {
        create.mutate(
            { name: values.name, contact_email: values.contact_email || undefined },
            { onSuccess: () => reset() },
        );
    });

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Nuevo cliente">
                <form onSubmit={onSubmit} className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                    <Field label="Nombre" error={errors.name?.message}>
                        <Input {...register('name')} />
                    </Field>
                    <Field label="Email de contacto" error={errors.contact_email?.message}>
                        <Input type="email" {...register('contact_email')} />
                    </Field>
                    <Button type="submit" disabled={create.isPending}>
                        Crear cliente
                    </Button>
                </form>
            </Card>

            <Card title="Clientes">
                <DataTable columns={columns} data={data} />
            </Card>
        </div>
    );
}
