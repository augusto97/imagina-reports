import { LayoutDashboard } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useResetPassword } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

/**
 * Password-reset screen, reached from the email link (#/reset-password?token=…&email=…).
 * Reads the token + email from the URL, lets the user set a new password, then sends them
 * back to sign in. Rendered pre-auth by App before the login gate.
 */
export function ResetPasswordScreen(): ReactElement {
    const reset = useResetPassword();
    const params = new URLSearchParams(window.location.hash.split('?')[1] ?? '');
    const token = params.get('token') ?? '';
    const email = params.get('email') ?? '';

    const [password, setPassword] = useState('');
    const [confirm, setConfirm] = useState('');
    const [error, setError] = useState('');

    const backToLogin = (): void => {
        window.location.hash = '#/';
        window.location.reload();
    };

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        setError('');
        if (password.length < 8) {
            setError('La contraseña debe tener al menos 8 caracteres.');

            return;
        }
        if (password !== confirm) {
            setError('Las contraseñas no coinciden.');

            return;
        }
        reset.mutate({ token, email, password, password_confirmation: confirm });
    };

    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-p-4 ir-text-foreground">
            <div className="ir-w-full ir-max-w-sm">
                <div className="ir-mb-6 ir-flex ir-items-center ir-justify-center ir-gap-2">
                    <LayoutDashboard className="ir-size-5 ir-text-primary" />
                    <span className="ir-text-lg ir-font-semibold">Imagina Reports</span>
                </div>
                <Card title="Nueva contraseña" description={email !== '' ? `Para ${email}` : undefined}>
                    {token === '' || email === '' ? (
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            <p className="ir-text-sm ir-text-red-500">El enlace no es válido. Solicita uno nuevo desde «¿Olvidaste tu contraseña?».</p>
                            <Button onClick={backToLogin}>Ir a iniciar sesión</Button>
                        </div>
                    ) : reset.isSuccess ? (
                        <div className="ir-flex ir-flex-col ir-gap-3">
                            <p className="ir-rounded-md ir-bg-emerald-500/10 ir-px-3 ir-py-2 ir-text-sm ir-text-emerald-700">Contraseña restablecida. Ya puedes iniciar sesión.</p>
                            <Button onClick={backToLogin}>Iniciar sesión</Button>
                        </div>
                    ) : (
                        <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                            <Field label="Nueva contraseña (mín. 8)">
                                <Input type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} autoFocus />
                            </Field>
                            <Field label="Repite la contraseña">
                                <Input type="password" autoComplete="new-password" value={confirm} onChange={(event) => setConfirm(event.target.value)} />
                            </Field>
                            {error !== '' && <p className="ir-text-xs ir-text-red-500">{error}</p>}
                            {reset.isError && <p className="ir-text-xs ir-text-red-500">El enlace no es válido o ha caducado. Solicita uno nuevo.</p>}
                            <Button type="submit" disabled={reset.isPending}>
                                {reset.isPending ? 'Guardando…' : 'Restablecer contraseña'}
                            </Button>
                            <button type="button" className="ir-mt-1 ir-self-center ir-text-xs ir-text-muted-foreground ir-underline hover:ir-text-foreground" onClick={backToLogin}>
                                ← Cancelar
                            </button>
                        </form>
                    )}
                </Card>
            </div>
        </div>
    );
}
