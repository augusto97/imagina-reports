import { type ReactElement, useEffect, useState } from 'react';

import { type AgencyUpdate, useAgency, useChangePassword, useUpdateAgency } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

function PasswordCard(): ReactElement {
    const change = useChangePassword();
    const [current, setCurrent] = useState('');
    const [next, setNext] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');

    const submit = (): void => {
        setError('');
        if (next !== confirm) {
            setError('La nueva contraseña y su confirmación no coinciden.');

            return;
        }
        change.mutate(
            { current_password: current, password: next, password_confirmation: confirm },
            {
                onSuccess: () => {
                    setCurrent('');
                    setNext('');
                    setConfirm('');
                },
                onError: () => setError('No se pudo cambiar. Revisa que la contraseña actual sea correcta y la nueva tenga al menos 8 caracteres.'),
            },
        );
    };

    return (
        <Card title="Cuenta — cambiar contraseña">
            <div className="ir-flex ir-flex-col ir-gap-4">
                <Field label="Contraseña actual">
                    <Input type="password" autoComplete="current-password" value={current} onChange={(event) => setCurrent(event.target.value)} />
                </Field>
                <Field label="Nueva contraseña (mín. 8)">
                    <Input type="password" autoComplete="new-password" value={next} onChange={(event) => setNext(event.target.value)} />
                </Field>
                <Field label="Repite la nueva contraseña">
                    <Input type="password" autoComplete="new-password" value={confirm} onChange={(event) => setConfirm(event.target.value)} />
                </Field>
                <div className="ir-flex ir-items-center ir-gap-3">
                    <Button onClick={submit} disabled={change.isPending || current === '' || next === ''}>
                        {change.isPending ? 'Cambiando…' : 'Cambiar contraseña'}
                    </Button>
                    {change.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Contraseña actualizada.</span>}
                    {error !== '' && <span className="ir-text-xs ir-text-red-500">{error}</span>}
                </div>
            </div>
        </Card>
    );
}

const LOCALES: { value: string; label: string }[] = [
    { value: 'es', label: 'Español' },
    { value: 'en', label: 'English' },
    { value: 'pt_BR', label: 'Português (BR)' },
];

export function SettingsScreen(): ReactElement {
    const { data: agency, isLoading } = useAgency();
    const update = useUpdateAgency();

    const [name, setName] = useState('');
    const [color, setColor] = useState('#6d28d9');
    const [locale, setLocale] = useState('es');
    const [apiKey, setApiKey] = useState('');

    useEffect(() => {
        if (agency !== undefined) {
            setName(agency.name);
            setColor(agency.brand_color ?? '#6d28d9');
            setLocale(agency.default_locale);
        }
    }, [agency]);

    if (isLoading || agency === undefined) {
        return <p className="ir-text-sm ir-text-muted-foreground">Cargando ajustes…</p>;
    }

    const save = (): void => {
        const payload: AgencyUpdate = { name, brand_color: color, default_locale: locale };
        if (apiKey !== '') {
            payload.anthropic_key = apiKey;
        }
        update.mutate(payload, { onSuccess: () => setApiKey('') });
    };

    return (
        <div className="ir-flex ir-flex-col ir-gap-6">
            <Card title="Marca (white-label)">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <Field label="Nombre de la agencia">
                        <Input value={name} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Color de marca">
                        <div className="ir-flex ir-items-center ir-gap-3">
                            <input
                                type="color"
                                value={color}
                                onChange={(event) => setColor(event.target.value)}
                                className="ir-h-9 ir-w-12 ir-rounded ir-border"
                            />
                            <Input value={color} onChange={(event) => setColor(event.target.value)} className="ir-w-32" />
                        </div>
                    </Field>
                    <Field label="Idioma por defecto">
                        <select
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                            value={locale}
                            onChange={(event) => setLocale(event.target.value)}
                        >
                            {LOCALES.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
            </Card>

            <Card title="Inteligencia artificial (Claude)">
                <div className="ir-flex ir-flex-col ir-gap-4">
                    <p className="ir-text-sm ir-text-muted-foreground">
                        La clave se guarda cifrada y nunca se muestra. Estado actual:{' '}
                        {agency.ai_key_set ? (
                            <span className="ir-font-medium ir-text-emerald-600">configurada ✓</span>
                        ) : (
                            <span className="ir-font-medium ir-text-amber-600">sin configurar</span>
                        )}
                        .
                    </p>
                    <Field label="Anthropic API key">
                        <Input
                            type="password"
                            autoComplete="off"
                            placeholder={agency.ai_key_set ? '•••••••• (deja en blanco para conservar)' : 'sk-ant-…'}
                            value={apiKey}
                            onChange={(event) => setApiKey(event.target.value)}
                        />
                    </Field>
                </div>
            </Card>

            <div className="ir-flex ir-items-center ir-gap-3">
                <Button onClick={save} disabled={update.isPending || name === ''}>
                    {update.isPending ? 'Guardando…' : 'Guardar ajustes'}
                </Button>
                {update.isSuccess && <span className="ir-text-xs ir-text-emerald-600">Ajustes guardados.</span>}
                {update.isError && <span className="ir-text-xs ir-text-red-500">No se pudieron guardar.</span>}
            </div>

            <PasswordCard />
        </div>
    );
}
