import { LayoutDashboard } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useForgotPassword, useLogin } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

export function LoginScreen(): ReactElement {
    const login = useLogin();
    const forgot = useForgotPassword();
    const [mode, setMode] = useState<'login' | 'forgot'>('login');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(true);
    // Set once the API answers `two_factor_required`: the password was right, we now need the code.
    const [needsCode, setNeedsCode] = useState(false);
    const [code, setCode] = useState('');

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (email === '' || password === '') {
            return;
        }
        login.mutate(
            { email, password, remember, ...(needsCode ? { two_factor_code: code } : {}) },
            {
                onSuccess: (result) => {
                    if ('two_factor_required' in result) {
                        setNeedsCode(true);
                    }
                },
            },
        );
    };

    const submitForgot = (event: FormEvent): void => {
        event.preventDefault();
        if (email === '') {
            return;
        }
        forgot.mutate(email);
    };

    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-p-4 ir-text-foreground">
            <div className="ir-w-full ir-max-w-sm">
                <div className="ir-mb-6 ir-flex ir-items-center ir-justify-center ir-gap-2">
                    <LayoutDashboard className="ir-size-5 ir-text-primary" />
                    <span className="ir-text-lg ir-font-semibold">Imagina Reports</span>
                </div>

                {mode === 'login' ? (
                    <Card title="Iniciar sesión">
                        <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                            <Field label="Email">
                                <Input type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} autoFocus />
                            </Field>
                            <Field label="Contraseña">
                                <Input type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} />
                            </Field>
                            {needsCode && (
                                <Field label="Código de verificación" hint="Los 6 dígitos de tu app de autenticación. También sirve un código de recuperación.">
                                    <Input
                                        autoComplete="one-time-code"
                                        inputMode="numeric"
                                        value={code}
                                        onChange={(event) => setCode(event.target.value)}
                                        placeholder="123456"
                                        autoFocus
                                    />
                                </Field>
                            )}
                            <label className="ir-flex ir-items-center ir-gap-2 ir-text-sm ir-text-muted-foreground">
                                <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
                                Recordarme en este dispositivo
                            </label>
                            {login.isError && (
                                <p className="ir-text-xs ir-text-red-500">
                                    {needsCode ? 'El código no es válido. Inténtalo de nuevo.' : 'No pudimos iniciar sesión. Revisa tu email y contraseña.'}
                                </p>
                            )}
                            <Button type="submit" disabled={login.isPending || (needsCode && code === '')}>
                                {login.isPending ? 'Entrando…' : needsCode ? 'Verificar y entrar' : 'Entrar'}
                            </Button>
                            <button
                                type="button"
                                className="ir-mt-1 ir-self-center ir-text-xs ir-text-muted-foreground ir-underline hover:ir-text-foreground"
                                onClick={() => { setMode('forgot'); login.reset(); }}
                            >
                                ¿Olvidaste tu contraseña?
                            </button>
                        </form>
                    </Card>
                ) : (
                    <Card title="Restablecer contraseña" description="Te enviaremos un enlace para crear una nueva.">
                        <form onSubmit={submitForgot} className="ir-flex ir-flex-col ir-gap-3">
                            <Field label="Email">
                                <Input type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} autoFocus />
                            </Field>
                            {forgot.isSuccess ? (
                                <p className="ir-rounded-md ir-bg-emerald-500/10 ir-px-3 ir-py-2 ir-text-xs ir-text-emerald-700">
                                    {forgot.data?.message ?? 'Si el correo existe, te enviamos un enlace.'} Revisa tu bandeja (y spam).
                                </p>
                            ) : (
                                <Button type="submit" disabled={forgot.isPending || email === ''}>
                                    {forgot.isPending ? 'Enviando…' : 'Enviar enlace'}
                                </Button>
                            )}
                            {forgot.isError && <p className="ir-text-xs ir-text-red-500">No se pudo enviar. Inténtalo de nuevo.</p>}
                            <button
                                type="button"
                                className="ir-mt-1 ir-self-center ir-text-xs ir-text-muted-foreground ir-underline hover:ir-text-foreground"
                                onClick={() => { setMode('login'); forgot.reset(); }}
                            >
                                ← Volver a iniciar sesión
                            </button>
                        </form>
                    </Card>
                )}
            </div>
        </div>
    );
}
