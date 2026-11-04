import { LayoutDashboard } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useForgotPassword, useLogin } from '../api';
import { Button, Field, Input } from '../components/ui';

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
        <div className="ir-grid ir-min-h-screen ir-bg-background ir-text-foreground lg:ir-grid-cols-[1.05fr_1fr]">
            {/* Brand panel (desktop): what the product does for the agency, in three lines. */}
            <aside className="ir-relative ir-hidden ir-overflow-hidden ir-bg-[hsl(230_35%_12%)] ir-p-12 ir-text-white lg:ir-flex lg:ir-flex-col lg:ir-justify-between">
                <div
                    aria-hidden
                    className="ir-pointer-events-none ir-absolute -ir-right-32 -ir-top-32 ir-size-[34rem] ir-rounded-full ir-opacity-60 ir-blur-3xl"
                    style={{ background: 'radial-gradient(circle, hsl(243 75% 59% / 0.55), transparent 65%)' }}
                />
                <div
                    aria-hidden
                    className="ir-pointer-events-none ir-absolute -ir-bottom-40 -ir-left-24 ir-size-[30rem] ir-rounded-full ir-opacity-50 ir-blur-3xl"
                    style={{ background: 'radial-gradient(circle, hsl(190 80% 45% / 0.35), transparent 65%)' }}
                />
                <div className="ir-relative ir-flex ir-items-center ir-gap-2.5">
                    <span className="ir-flex ir-size-9 ir-items-center ir-justify-center ir-rounded-lg ir-bg-white/10 ir-ring-1 ir-ring-white/15">
                        <LayoutDashboard className="ir-size-4" />
                    </span>
                    <span className="ir-text-base ir-font-semibold ir-tracking-tight">Imagina Reports</span>
                </div>
                <div className="ir-relative ir-max-w-md">
                    <h2 className="ir-text-3xl ir-font-semibold ir-leading-tight ir-tracking-tight">El trabajo invisible de tu agencia, convertido en un reporte que tus clientes entienden.</h2>
                    <ul className="ir-mt-8 ir-flex ir-flex-col ir-gap-3 ir-text-sm ir-text-white/75">
                        {['Analytics, Search Console, seguridad y mantenimiento en un solo reporte', 'Con tu marca, en PDF y en un panel interactivo', 'El resumen lo redacta la IA; tú solo lo revisas'].map((line) => (
                            <li key={line} className="ir-flex ir-items-start ir-gap-2.5">
                                <span className="ir-mt-1.5 ir-size-1.5 ir-shrink-0 ir-rounded-full ir-bg-[hsl(243_75%_72%)]" />
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>
                <p className="ir-relative ir-text-xs ir-text-white/45">Reportes que retienen clientes.</p>
            </aside>

            <div className="ir-flex ir-items-center ir-justify-center ir-p-6">
            <div className="ir-w-full ir-max-w-sm">
                <div className="ir-mb-8 ir-flex ir-items-center ir-gap-2 lg:ir-hidden">
                    <span className="ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-lg ir-bg-primary ir-text-primary-foreground">
                        <LayoutDashboard className="ir-size-4" />
                    </span>
                    <span className="ir-text-base ir-font-semibold">Imagina Reports</span>
                </div>
                <h1 className="ir-text-2xl ir-font-semibold ir-tracking-tight">{mode === 'login' ? 'Bienvenido de nuevo' : 'Recupera tu acceso'}</h1>
                <p className="ir-mb-6 ir-mt-1 ir-text-sm ir-text-muted-foreground">
                    {mode === 'login' ? 'Entra para ver qué necesita tu atención hoy.' : 'Te enviamos un enlace para crear una contraseña nueva.'}
                </p>

                {mode === 'login' ? (
                    <div>
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
                    </div>
                ) : (
                    <div>
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
                    </div>
                )}
            </div>
            </div>
        </div>
    );
}
