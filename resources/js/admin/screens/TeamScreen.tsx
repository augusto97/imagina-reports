import { Trash2, UserPlus } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useCreateTeamMember, useDeleteTeamMember, useTeam, useUpdateTeamMember } from '../api';
import { Badge, Button, Card, Field, Input, Select } from '../components/ui';
import type { TeamMember } from '../types';

const ROLE_LABEL: Record<string, string> = { owner: 'Propietario', admin: 'Administrador', collaborator: 'Colaborador' };
const ROLE_TONE: Record<string, 'accent' | 'info' | 'neutral'> = { owner: 'accent', admin: 'info', collaborator: 'neutral' };

/**
 * Agency team management (SaaS Fase 1): invite teammates, change their role, remove them.
 * The `max_users` plan limit is enforced by the API on create. Only privileged users
 * (owner/admin) reach this screen.
 */
export function TeamScreen(): ReactElement {
    const { data: members = [], isLoading } = useTeam();
    const create = useCreateTeamMember();
    const update = useUpdateTeamMember();
    const remove = useDeleteTeamMember();

    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'collaborator' });
    const set = (key: keyof typeof form, value: string): void => setForm((prev) => ({ ...prev, [key]: value }));

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        create.mutate(
            { name: form.name.trim(), email: form.email.trim(), password: form.password, role: form.role },
            { onSuccess: () => setForm({ name: '', email: '', password: '', role: 'collaborator' }) },
        );
    };

    const owners = members.filter((m) => m.role === 'owner').length;
    const canRemove = (member: TeamMember): boolean => !member.is_self && !(member.role === 'owner' && owners <= 1);

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <div>
                <h1 className="ir-text-lg ir-font-semibold ir-tracking-tight">Equipo</h1>
                <p className="ir-mt-1 ir-max-w-2xl ir-text-sm ir-text-muted-foreground">
                    Las personas de tu agencia que pueden iniciar sesión. Propietario y administrador gestionan todo; el colaborador trabaja pero no administra el equipo ni la facturación.
                </p>
            </div>

            <Card title="Invitar a un miembro" description="Crea su cuenta con estas credenciales y compártele el acceso.">
                <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="Nombre">
                            <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
                        </Field>
                        <Field label="Email">
                            <Input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} />
                        </Field>
                    </div>
                    <div className="ir-grid ir-gap-3 sm:ir-grid-cols-2">
                        <Field label="Contraseña (mín. 8)">
                            <Input type="text" value={form.password} onChange={(e) => set('password', e.target.value)} placeholder="Se la compartes al miembro" />
                        </Field>
                        <Field label="Rol">
                            <Select value={form.role} onChange={(e) => set('role', e.target.value)}>
                                <option value="collaborator">Colaborador</option>
                                <option value="admin">Administrador</option>
                                <option value="owner">Propietario</option>
                            </Select>
                        </Field>
                    </div>
                    {create.isError && <p className="ir-text-xs ir-text-danger">No se pudo crear. Revisa el email (¿ya existe?) o si alcanzaste el límite de usuarios de tu plan.</p>}
                    <div className="ir-flex ir-justify-end">
                        <Button type="submit" disabled={create.isPending || form.name.trim() === '' || form.email.trim() === '' || form.password.length < 8}>
                            <UserPlus className="ir-size-4" />
                            {create.isPending ? 'Añadiendo…' : 'Añadir miembro'}
                        </Button>
                    </div>
                </form>
            </Card>

            <Card title={`Miembros (${members.length})`}>
                {isLoading ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
                ) : (
                    <ul className="ir-flex ir-flex-col ir-divide-y">
                        {members.map((member) => (
                            <li key={member.id} className="ir-flex ir-flex-wrap ir-items-center ir-gap-3 ir-py-3 ir-text-sm">
                                <div className="ir-min-w-0 ir-flex-1">
                                    <p className="ir-flex ir-items-center ir-gap-2 ir-font-medium">
                                        {member.name}
                                        {member.is_self && <Badge tone="neutral">Tú</Badge>}
                                    </p>
                                    <p className="ir-text-xs ir-text-muted-foreground">{member.email}</p>
                                </div>
                                <Select
                                    className="ir-h-8 ir-w-40 ir-text-xs"
                                    value={member.role}
                                    disabled={member.role === 'owner' && owners <= 1}
                                    onChange={(e) => update.mutate({ id: member.id, role: e.target.value })}
                                >
                                    <option value="collaborator">Colaborador</option>
                                    <option value="admin">Administrador</option>
                                    <option value="owner">Propietario</option>
                                </Select>
                                <Badge tone={ROLE_TONE[member.role] ?? 'neutral'}>{ROLE_LABEL[member.role] ?? member.role}</Badge>
                                <button
                                    type="button"
                                    className="ir-rounded ir-p-1 ir-text-muted-foreground enabled:hover:ir-bg-danger/10 enabled:hover:ir-text-danger disabled:ir-opacity-30"
                                    title={canRemove(member) ? 'Eliminar' : 'No se puede eliminar'}
                                    disabled={!canRemove(member)}
                                    onClick={() => { if (window.confirm(`¿Eliminar a ${member.name}?`)) remove.mutate(member.id); }}
                                >
                                    <Trash2 className="ir-size-4" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}
