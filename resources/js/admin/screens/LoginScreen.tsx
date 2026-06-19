import { LayoutDashboard } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

import { useLogin } from '../api';
import { Button, Card, Field, Input } from '../components/ui';

export function LoginScreen(): ReactElement {
    const login = useLogin();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (email === '' || password === '') {
            return;
        }
        login.mutate({ email, password });
    };

    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-p-4 ir-text-foreground">
            <div className="ir-w-full ir-max-w-sm">
                <div className="ir-mb-6 ir-flex ir-items-center ir-justify-center ir-gap-2">
                    <LayoutDashboard className="ir-size-5 ir-text-primary" />
                    <span className="ir-text-lg ir-font-semibold">Imagina Reports</span>
                </div>
                <Card title="Iniciar sesión">
                    <form onSubmit={submit} className="ir-flex ir-flex-col ir-gap-3">
                        <Field label="Email">
                            <Input
                                type="email"
                                autoComplete="username"
                                value={email}
                                onChange={(event) => setEmail(event.target.value)}
                                autoFocus
                            />
                        </Field>
                        <Field label="Contraseña">
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                            />
                        </Field>
                        {login.isError && (
                            <p className="ir-text-xs ir-text-red-500">
                                No pudimos iniciar sesión. Revisa tu email y contraseña.
                            </p>
                        )}
                        <Button type="submit" disabled={login.isPending}>
                            {login.isPending ? 'Entrando…' : 'Entrar'}
                        </Button>
                    </form>
                </Card>
            </div>
        </div>
    );
}
