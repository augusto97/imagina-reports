import { zodResolver } from '@hookform/resolvers/zod';
import { type ColumnDef } from '@tanstack/react-table';
import { type ReactElement, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { useClients, useCreateClient, useDeleteClient, useUpdateClient } from '../api';
import { DataTable } from '../components/DataTable';
import { Button, Card, Field, Input } from '../components/ui';
import { TIMEZONES } from '../timezones';
import type { Client } from '../types';

const selectClass = 'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm';

const schema = z.object({
    name: z.string().min(1, 'Requerido'),
    contact_email: z.string().email('Email inválido').optional().or(z.literal('')),
});

type Values = z.infer<typeof schema>;

/** Inline edit form for an existing client (name, email, idioma, notas). */
function ClientEditForm({ client, onClose }: { client: Client; onClose: () => void }): ReactElement {
    const update = useUpdateClient(client.id);
    const [name, setName] = useState(client.name);
    const [email, setEmail] = useState(client.contact_email ?? '');
    const [locale, setLocale] = useState(client.locale ?? '');
    const [timezone, setTimezone] = useState(client.timezone ?? '');
    const [notes, setNotes] = useState(client.notes ?? '');

    const save = (): void => {
        update.mutate(
            {
                name,
                contact_email: email === '' ? null : email,
                locale: locale === '' ? null : locale,
                timezone: timezone === '' ? null : timezone,
                notes: notes === '' ? null : notes,
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Card title={`Editar «${client.name}»`}>
            <div className="ir-flex ir-max-w-md ir-flex-col ir-gap-3">
                <Field label="Nombre">
                    <Input value={name} onChange={(event) => setName(event.target.value)} />
                </Field>
                <Field label="Email de contacto">
                    <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} />
                </Field>
                <Field label="Idioma (ej. es, en, pt-BR)">
                    <Input value={locale} onChange={(event) => setLocale(event.target.value)} placeholder="es" />
                </Field>
                <Field label="Zona horaria (para fechas en los informes)">
                    <select className={selectClass} value={timezone} onChange={(event) => setTimezone(event.target.value)}>
                        <option value="">Sin definir (UTC)</option>
                        {TIMEZONES.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Notas">
                    <Input value={notes} onChange={(event) => setNotes(event.target.value)} />
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

export function ClientsScreen(): ReactElement {
    const { data = [] } = useClients();
    const create = useCreateClient();
    const remove = useDeleteClient();
    const [editing, setEditing] = useState<Client | null>(null);
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

    const confirmRemove = (client: Client): void => {
        if (window.confirm(`¿Eliminar el cliente «${client.name}»? (No se puede si todavía tiene sitios.)`)) {
            remove.mutate(client.id, {
                onSuccess: () => setEditing((current) => (current?.id === client.id ? null : current)),
                onError: () => window.alert('No se pudo eliminar: el cliente todavía tiene sitios. Elimínalos o reasígnalos primero.'),
            });
        }
    };

    const columns: ColumnDef<Client>[] = [
        { header: 'Nombre', accessorKey: 'name' },
        { header: 'Email', accessorKey: 'contact_email' },
        { header: 'Idioma', accessorKey: 'locale' },
        { header: 'Zona horaria', accessorKey: 'timezone' },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => (
                <div className="ir-flex ir-items-center ir-gap-2">
                    <Button variant="ghost" onClick={() => setEditing(row.original)}>
                        Editar
                    </Button>
                    <Button variant="ghost" onClick={() => confirmRemove(row.original)} disabled={remove.isPending}>
                        Eliminar
                    </Button>
                </div>
            ),
        },
    ];

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

            {editing !== null && <ClientEditForm key={editing.id} client={editing} onClose={() => setEditing(null)} />}

            <Card title="Clientes">
                <DataTable columns={columns} data={data} />
            </Card>
        </div>
    );
}
