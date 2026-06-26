import { Lock } from 'lucide-react';
import { type FormEvent, type ReactElement, useState } from 'react';

/**
 * Password gate for a protected public report/portal (CLAUDE.md §10/Etapa D). Shown when
 * the API answers 401 `requires_password`; on submit it re-fetches with the typed password.
 */
export function PasswordPrompt({ onSubmit, error }: { onSubmit: (password: string) => void; error?: boolean }): ReactElement {
    const [password, setPassword] = useState('');

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        if (password !== '') {
            onSubmit(password);
        }
    };

    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-p-4">
            <form onSubmit={submit} className="ir-w-full ir-max-w-sm ir-rounded-xl ir-border ir-bg-card ir-p-6 ir-shadow-sm">
                <div className="ir-mb-4 ir-flex ir-size-10 ir-items-center ir-justify-center ir-rounded-full ir-bg-primary/10">
                    <Lock className="ir-size-5 ir-text-primary" />
                </div>
                <h1 className="ir-text-lg ir-font-semibold ir-text-foreground">Informe protegido</h1>
                <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">
                    Introduce la contraseña para ver este informe.
                </p>
                <input
                    type="password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    autoFocus
                    autoComplete="current-password"
                    placeholder="Contraseña"
                    className="ir-mt-4 ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm ir-text-foreground"
                />
                {error === true && <p className="ir-mt-2 ir-text-xs ir-text-danger">Contraseña incorrecta. Inténtalo de nuevo.</p>}
                <button
                    type="submit"
                    className="ir-mt-4 ir-w-full ir-rounded-md ir-bg-primary ir-px-3 ir-py-2 ir-text-sm ir-font-medium ir-text-primary-foreground hover:ir-opacity-90"
                >
                    Ver informe
                </button>
            </form>
        </div>
    );
}
