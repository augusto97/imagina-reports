import { LayoutDashboard } from 'lucide-react';
import { type ReactElement, useEffect, useRef } from 'react';

import { useVerifyEmailChange } from '../api';
import { Button, Card } from '../components/ui';

/**
 * Confirms a pending email change, reached from the link sent to the NEW address
 * (#/verify-email?token=…). Rendered pre-auth: the user may well open the link in another
 * browser where they aren't signed in. Submits on mount — nothing to fill in.
 */
export function VerifyEmailScreen(): ReactElement {
    const verify = useVerifyEmailChange();
    const params = new URLSearchParams(window.location.hash.split('?')[1] ?? '');
    const token = params.get('token') ?? '';
    const submitted = useRef(false);

    useEffect(() => {
        // StrictMode mounts twice in dev; the token is single-use, so guard the call.
        if (token !== '' && !submitted.current) {
            submitted.current = true;
            verify.mutate(token);
        }
    }, [token, verify]);

    const goToLogin = (): void => {
        window.location.hash = '#/';
        window.location.reload();
    };

    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-p-4 ir-text-foreground">
            <div className="ir-w-full ir-max-w-sm">
                <div className="ir-mb-6 ir-flex ir-items-center ir-justify-center ir-gap-2">
                    <LayoutDashboard className="ir-size-5 ir-text-primary" />
                    <span className="ir-text-lg ir-font-semibold">Imagina Reports</span>
                </div>
                <Card title="Confirmar tu correo">
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        {token === '' && <p className="ir-text-sm ir-text-red-500">El enlace no es válido. Solicita el cambio de nuevo desde Ajustes.</p>}
                        {token !== '' && verify.isPending && <p className="ir-text-sm ir-text-muted-foreground">Confirmando…</p>}
                        {verify.isSuccess && (
                            <p className="ir-rounded-md ir-bg-emerald-500/10 ir-px-3 ir-py-2 ir-text-sm ir-text-emerald-700">
                                Listo. Tu correo de acceso ahora es <strong>{verify.data?.email}</strong>.
                            </p>
                        )}
                        {verify.isError && (
                            <p className="ir-text-sm ir-text-red-500">
                                El enlace no es válido o ha caducado. Vuelve a solicitar el cambio desde Ajustes.
                            </p>
                        )}
                        <Button onClick={goToLogin}>Ir a iniciar sesión</Button>
                    </div>
                </Card>
            </div>
        </div>
    );
}
