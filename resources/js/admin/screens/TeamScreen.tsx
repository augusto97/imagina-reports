import { KeyRound, Trash2, UserPlus } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { apiErrorMessage } from '@shared/lib/api';

import { useCreateTeamMember, useDeleteTeamMember, useTeam, useUpdateTeamMember } from '../api';
import { ActionMenu, Avatar, Badge, Button, Card, Field, Input, PageHeader, Select } from '../components/ui';
import type { TeamMember } from '../types';


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

    const [inviteOpen, setInviteOpen] = useState(false);
    const [form, setForm] = useState({ name: '', email: '', password: '', role: 'collaborator' });
    const set = (key: keyof typeof form, value: string): void => setForm((prev) => ({ ...prev, [key]: value }));

    // Inline "set a new password for this member" (admin action — no current password needed).
    const [pwFor, setPwFor] = useState<number | null>(null);
    const [pwValue, setPwValue] = useState('');
    const savePassword = (id: number): void => {
        if (pwValue.length < 8) {
            return;
        }
        update.mutate({ id, password: pwValue }, { onSuccess: () => { setPwFor(null); setPwValue(''); } });
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        create.mutate(
            { name: form.name.trim(), email: form.email.trim(), password: form.password, role: form.role },
            {
                onSuccess: () => {
                    setForm({ name: '', email: '', password: '', role: 'collaborator' });
                    setInviteOpen(false);
                },
            },
        );
    };

    const owners = members.filter((m) => m.role === 'owner').length;
    const canRemove = (member: TeamMember): boolean => !member.is_self && !(member.role === 'owner' && owners <= 1);

    return (
        <div className="ir-flex ir-flex-col ir-gap-5">
            <PageHeader
                className="ir-mb-1"
                title="Equipo"
                description="Las personas de tu agencia que pueden entrar. Propietario y administrador gestionan todo; el colaborador trabaja pero no administra el equipo ni la facturación."
                actions={
                    !inviteOpen ? (
                        <Button onClick={() => setInviteOpen(true)}>
                            <UserPlus className="ir-size-4" />
                            Añadir miembro
                        </Button>
                    ) : undefined
                }
            />

            {inviteOpen && (
            <Card
                title="Añadir a un miembro"
                description="Crea su cuenta con estas credenciales y compártele el acceso."
                actions={
                    <Button variant="quiet" size="sm" onClick={() => setInviteOpen(false)}>
                        Cerrar
                    </Button>
                }
            >
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
            )}

            <Card title={`Miembros (${members.length})`}>
                {isLoading ? (
                    <p className="ir-text-sm ir-text-muted-foreground">Cargando…</p>
                ) : (
                    <ul className="ir-flex ir-flex-col ir-divide-y">
                        {members.map((member) => (
                            <li key={member.id} className="ir-flex ir-flex-col ir-gap-2 ir-py-3 ir-text-sm">
                                <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-3">
                                    <Avatar name={member.name} />
                                    <div className="ir-min-w-0 ir-flex-1">
                                        <p className="ir-flex ir-items-center ir-gap-2 ir-font-medium">
                                            {member.name}
                                            {member.is_self && <Badge tone="neutral">Tú</Badge>}
                                        </p>
                                        <p className="ir-truncate ir-text-xs ir-text-muted-foreground">{member.email}</p>
                                    </div>
                                    <Select
                                        className="ir-h-8 ir-w-40 ir-text-xs"
                                        value={member.role}
                                        disabled={member.role === 'owner' && owners <= 1}
                                        onChange={(e) => update.mutate({ id: member.id, role: e.target.value })}
                                        aria-label={`Rol de ${member.name}`}
                                    >
                                        <option value="collaborator">Colaborador</option>
                                        <option value="admin">Administrador</option>
                                        <option value="owner">Propietario</option>
                                    </Select>
                                    <ActionMenu
                                        label={`Acciones de ${member.name}`}
                                        items={[
                                            {
                                                label: 'Cambiar contraseña',
                                                icon: KeyRound,
                                                onSelect: () => {
                                                    setPwFor((cur) => (cur === member.id ? null : member.id));
                                                    setPwValue('');
                                                },
                                            },
                                            {
                                                label: 'Eliminar del equipo',
                                                icon: Trash2,
                                                danger: true,
                                                disabled: !canRemove(member),
                                                hint: canRemove(member) ? undefined : 'No se puede eliminar al único propietario ni a ti',
                                                onSelect: () => {
                                                    if (window.confirm(`¿Eliminar a ${member.name}? Esta acción no se puede deshacer.`)) remove.mutate(member.id);
                                                },
                                            },
                                        ]}
                                    />
                                </div>
                                {pwFor === member.id && (
                                    <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-2 ir-rounded-md ir-bg-muted/40 ir-p-2">
                                        <span className="ir-text-xs ir-text-muted-foreground">Nueva contraseña para {member.name} (no necesitas la actual):</span>
                                        <Input type="text" className="ir-h-8 ir-w-56 ir-text-xs" value={pwValue} onChange={(e) => setPwValue(e.target.value)} placeholder="mín. 8 caracteres" />
                                        <Button size="sm" onClick={() => savePassword(member.id)} disabled={update.isPending || pwValue.length < 8}>
                                            Guardar
                                        </Button>
                                        <Button size="sm" variant="ghost" onClick={() => { setPwFor(null); setPwValue(''); }}>Cancelar</Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {/* Surface a rejected role change / removal (e.g. "can't remove the last owner")
                    instead of silently discarding the server's message. */}
                {(update.isError || remove.isError) && (
                    <p className="ir-mt-3 ir-text-xs ir-text-danger">{apiErrorMessage(update.error ?? remove.error, 'No se pudo actualizar el equipo.')}</p>
                )}
            </Card>
        </div>
    );
}
