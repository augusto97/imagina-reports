import { Check, Copy, Globe, LayoutDashboard, Lock, RefreshCw, ShieldOff } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { useRotateDashboardToken, useUpdateReportSharing } from '../api';
import type { ReportDefinitionDto, ReportVisibility } from '../types';
import { Button, Card, Field, Input, Modal } from './ui';

const VISIBILITY_OPTIONS: { value: ReportVisibility; label: string; hint: string; icon: typeof Globe }[] = [
    { value: 'public', label: 'Público', hint: 'Cualquiera con el enlace puede verlo.', icon: Globe },
    { value: 'password', label: 'Con contraseña', hint: 'Pide una contraseña antes de mostrar el informe.', icon: Lock },
    { value: 'private', label: 'Privado', hint: 'No accesible por enlace; solo se genera el PDF.', icon: ShieldOff },
];

/**
 * Sharing & privacy for a report definition (CLAUDE.md §10/Etapa D): visibility,
 * an optional password, the public link, and the embed-domain allowlist. The PDF is
 * unaffected — it always renders via the server-only print token.
 */
export function ShareDialog({
    definition,
    publicToken,
    onClose,
}: {
    definition: ReportDefinitionDto;
    publicToken: string | null;
    onClose: () => void;
}): ReactElement {
    const save = useUpdateReportSharing();
    const rotate = useRotateDashboardToken();

    const [visibility, setVisibility] = useState<ReportVisibility>(definition.visibility);
    const [password, setPassword] = useState('');
    const [domains, setDomains] = useState((definition.embed_domains ?? []).join('\n'));
    const [dashboardEnabled, setDashboardEnabled] = useState(definition.dashboard_enabled);
    const [rotatedToken, setRotatedToken] = useState<string | null>(null);
    const [copied, setCopied] = useState(false);
    const [dashCopied, setDashCopied] = useState(false);

    const [embedCopied, setEmbedCopied] = useState(false);

    const link = publicToken !== null ? `${window.location.origin}/reports/${publicToken}` : null;
    const embedCode =
        publicToken !== null
            ? `<iframe src="${window.location.origin}/embed/${publicToken}" width="100%" height="800" frameborder="0" style="border:0"></iframe>`
            : null;

    const copyLink = async (): Promise<void> => {
        if (link === null) {
            return;
        }
        await navigator.clipboard.writeText(link);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 1500);
    };

    const copyEmbed = async (): Promise<void> => {
        if (embedCode === null) {
            return;
        }
        await navigator.clipboard.writeText(embedCode);
        setEmbedCopied(true);
        window.setTimeout(() => setEmbedCopied(false), 1500);
    };

    // The live dashboard link uses the definition's own token (set once enabled & saved).
    const dashboardToken = rotatedToken ?? definition.dashboard_token;
    const dashboardLink = dashboardToken !== null ? `${window.location.origin}/dashboard/${dashboardToken}` : null;

    const rotateToken = (): void => {
        if (window.confirm('¿Regenerar el enlace? Los enlaces e incrustaciones anteriores dejarán de funcionar.')) {
            rotate.mutate(definition.id, { onSuccess: (updated) => setRotatedToken(updated.dashboard_token) });
        }
    };

    const copyDashboard = async (): Promise<void> => {
        if (dashboardLink === null) {
            return;
        }
        await navigator.clipboard.writeText(dashboardLink);
        setDashCopied(true);
        window.setTimeout(() => setDashCopied(false), 1500);
    };

    const submit = (): void => {
        const embedDomains = domains
            .split(/[\n,]/)
            .map((value) => value.trim())
            .filter((value) => value !== '');

        save.mutate(
            {
                id: definition.id,
                visibility,
                // Only send a password when typing a new one in "password" mode.
                password: visibility === 'password' && password !== '' ? password : null,
                embed_domains: embedDomains,
                dashboard_enabled: dashboardEnabled,
            },
            { onSuccess: onClose },
        );
    };

    return (
        <Modal onClose={onClose} className="ir-max-w-xl">
            <Card title={`Compartir · ${definition.name}`}>
                <div className="ir-flex ir-flex-col ir-gap-5">
                    {link !== null && (
                        <Field label="Enlace público">
                            <div className="ir-flex ir-gap-2">
                                <Input readOnly value={link} onFocus={(event) => event.currentTarget.select()} className="ir-font-mono ir-text-xs" />
                                <Button type="button" variant="outline" size="sm" onClick={() => void copyLink()} title="Copiar enlace">
                                    {copied ? <Check className="ir-size-4 ir-text-success" /> : <Copy className="ir-size-4" />}
                                </Button>
                            </div>
                        </Field>
                    )}

                    <div className="ir-flex ir-flex-col ir-gap-2">
                        <span className="ir-text-xs ir-font-medium ir-text-muted-foreground">Visibilidad</span>
                        <div className="ir-grid ir-gap-2">
                            {VISIBILITY_OPTIONS.map((option) => {
                                const Icon = option.icon;
                                const active = visibility === option.value;

                                return (
                                    <button
                                        key={option.value}
                                        type="button"
                                        onClick={() => setVisibility(option.value)}
                                        className={`ir-flex ir-items-start ir-gap-3 ir-rounded-lg ir-border ir-p-3 ir-text-left ir-transition ${
                                            active ? 'ir-border-primary ir-bg-primary/5 ir-ring-1 ir-ring-primary' : 'ir-border-border hover:ir-bg-muted/50'
                                        }`}
                                    >
                                        <Icon className={`ir-mt-0.5 ir-size-4 ${active ? 'ir-text-primary' : 'ir-text-muted-foreground'}`} />
                                        <span className="ir-flex ir-flex-col">
                                            <span className="ir-text-sm ir-font-medium">{option.label}</span>
                                            <span className="ir-text-xs ir-text-muted-foreground">{option.hint}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {visibility === 'password' && (
                        <Field label={definition.has_password ? 'Nueva contraseña (dejar vacío para mantener la actual)' : 'Contraseña'}>
                            <Input
                                type="password"
                                value={password}
                                onChange={(event) => setPassword(event.target.value)}
                                placeholder={definition.has_password ? '••••••••' : 'Mínimo 4 caracteres'}
                                autoComplete="new-password"
                            />
                        </Field>
                    )}

                    <Field label="Dominios permitidos para incrustar (uno por línea)">
                        <textarea
                            value={domains}
                            onChange={(event) => setDomains(event.target.value)}
                            rows={3}
                            placeholder="ejemplo.com&#10;panel.cliente.com"
                            className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-font-mono ir-text-xs"
                        />
                        <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                            Solo estos sitios podrán mostrar el informe dentro de un iframe. Vacío = ninguno.
                        </p>
                    </Field>

                    {embedCode !== null && (
                        <Field label="Código para incrustar">
                            <div className="ir-flex ir-gap-2">
                                <textarea
                                    readOnly
                                    value={embedCode}
                                    rows={2}
                                    onFocus={(event) => event.currentTarget.select()}
                                    className="ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-font-mono ir-text-xs"
                                />
                                <Button type="button" variant="outline" size="sm" onClick={() => void copyEmbed()} title="Copiar código">
                                    {embedCopied ? <Check className="ir-size-4 ir-text-success" /> : <Copy className="ir-size-4" />}
                                </Button>
                            </div>
                            <p className="ir-mt-1 ir-text-xs ir-text-muted-foreground">
                                Pega esto en tu web. Recuerda guardar el dominio en la lista de arriba.
                            </p>
                        </Field>
                    )}

                    <div className="ir-rounded-lg ir-border ir-p-3">
                        <label className="ir-flex ir-items-start ir-gap-3">
                            <input
                                type="checkbox"
                                checked={dashboardEnabled}
                                onChange={(event) => setDashboardEnabled(event.target.checked)}
                                className="ir-mt-0.5 ir-size-4"
                            />
                            <span className="ir-flex ir-flex-col">
                                <span className="ir-flex ir-items-center ir-gap-1.5 ir-text-sm ir-font-medium">
                                    <LayoutDashboard className="ir-size-4 ir-text-muted-foreground" />
                                    Panel en vivo
                                </span>
                                <span className="ir-text-xs ir-text-muted-foreground">
                                    Publica un panel permanente y siempre actualizado donde el cliente elige el rango de fechas.
                                </span>
                            </span>
                        </label>

                        {dashboardEnabled && dashboardLink !== null && (
                            <>
                                <div className="ir-mt-3 ir-flex ir-gap-2">
                                    <Input readOnly value={dashboardLink} onFocus={(event) => event.currentTarget.select()} className="ir-font-mono ir-text-xs" />
                                    <Button type="button" variant="outline" size="sm" onClick={() => void copyDashboard()} title="Copiar enlace del panel">
                                        {dashCopied ? <Check className="ir-size-4 ir-text-success" /> : <Copy className="ir-size-4" />}
                                    </Button>
                                    <Button type="button" variant="ghost" size="sm" onClick={rotateToken} disabled={rotate.isPending} title="Regenerar enlace (revoca los anteriores)">
                                        <RefreshCw className="ir-size-4" />
                                    </Button>
                                </div>
                                {rotatedToken !== null && <p className="ir-mt-1 ir-text-xs ir-text-success">Enlace regenerado. Los anteriores ya no funcionan.</p>}
                            </>
                        )}
                        {dashboardEnabled && dashboardLink === null && (
                            <p className="ir-mt-2 ir-text-xs ir-text-muted-foreground">Guarda para generar el enlace del panel.</p>
                        )}
                    </div>

                    <div className="ir-flex ir-justify-end ir-gap-2">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="button" onClick={submit} disabled={save.isPending}>
                            {save.isPending ? 'Guardando…' : 'Guardar'}
                        </Button>
                    </div>
                </div>
            </Card>
        </Modal>
    );
}
