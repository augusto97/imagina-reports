import { Loader2, Sparkles, X } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { cn } from '@shared/lib/utils';

import { Button } from '../components/ui';

/** Ready-made requests: a starting point the person edits, not a fixed menu. */
const EXAMPLES: { label: string; text: string }[] = [
    {
        label: 'Informe 360 multipágina',
        text: 'Un informe mensual completo y multipágina para el cliente: portada, una página de resumen con el estado general y las cifras clave, una página por cada área con datos (seguridad, disponibilidad, tráfico, SEO, anuncios, ventas, mantenimiento) y una contraportada. Usa todas las fuentes conectadas. Lenguaje sencillo, sin tecnicismos.',
    },
    {
        label: 'Tráfico y SEO',
        text: 'Un informe centrado en tráfico y posicionamiento: visitas y usuarios con su evolución, de dónde llegan, qué páginas ven, en qué países están, y cómo aparece el sitio en Google (clics, impresiones, posición y búsquedas principales).',
    },
    {
        label: 'Seguridad y mantenimiento',
        text: 'Un informe que muestre el trabajo invisible: ataques bloqueados, estado de seguridad, disponibilidad del sitio, actualizaciones aplicadas y lo que hicimos este mes.',
    },
    {
        label: 'Tienda online',
        text: 'Un informe para una tienda online: ingresos, pedidos, clientes nuevos, productos más vendidos y su evolución, junto con el tráfico que trae esas ventas.',
    },
    {
        label: 'Resumen de una página',
        text: 'Un resumen ejecutivo de una sola página: estado general, las 6-8 cifras más importantes del mes y un diagnóstico con recomendaciones.',
    },
];

/**
 * «Generar con IA»: describe the report the way you'd ask someone on your team. The full-report
 * request replaces the canvas; a section is added below what's there. Errors say why (bad key,
 * no balance, metrics the site doesn't have…) and keep what was written.
 */
export function AiGenerateModal({
    onClose,
    siteReady,
    replacing,
    fullPending,
    sectionPending,
    error,
    onFull,
    onSection,
}: {
    onClose: () => void;
    siteReady: boolean;
    /** The canvas already has content (the full report replaces it). */
    replacing: boolean;
    fullPending: boolean;
    sectionPending: boolean;
    error: string | null;
    onFull: (prompt: string) => void;
    onSection: (prompt: string) => void;
}): ReactElement {
    const [mode, setMode] = useState<'full' | 'section'>('full');
    const [full, setFull] = useState('');
    const [section, setSection] = useState('');
    const pending = fullPending || sectionPending;

    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-overflow-y-auto ir-bg-black/40 ir-p-4 ir-pt-[6vh]">
            <button type="button" aria-label="Cerrar" onClick={onClose} className="ir-fixed ir-inset-0 ir-cursor-default" />
            <div className="ir-relative ir-z-10 ir-w-full ir-max-w-2xl ir-rounded-xl ir-border ir-bg-card ir-p-6 ir-shadow-ir-lg">
                <div className="ir-mb-4 ir-flex ir-items-start ir-justify-between ir-gap-3">
                    <div>
                        <h2 className="ir-flex ir-items-center ir-gap-2 ir-text-base ir-font-semibold ir-tracking-tight">
                            <Sparkles className="ir-size-4 ir-text-primary" />
                            Generar con IA
                        </h2>
                        <p className="ir-mt-1 ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">
                            Descríbelo como se lo pedirías a alguien de tu equipo. La IA usa las fuentes conectadas de este sitio y solo pone métricas que existen; luego lo ajustas a mano.
                        </p>
                    </div>
                    <button type="button" onClick={onClose} aria-label="Cerrar" className="ir-rounded-md ir-p-1 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground">
                        <X className="ir-size-4" />
                    </button>
                </div>

                <div className="ir-mb-4 ir-inline-flex ir-rounded-lg ir-border ir-bg-muted/50 ir-p-0.5 ir-text-[13px]">
                    {(
                        [
                            ['full', 'Informe completo'],
                            ['section', 'Añadir una sección'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setMode(key)}
                            className={cn('ir-rounded-md ir-px-3 ir-py-1.5 ir-font-medium ir-transition-colors', mode === key ? 'ir-bg-card ir-shadow-sm' : 'ir-text-muted-foreground hover:ir-text-foreground')}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {mode === 'full' ? (
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <label className="ir-flex ir-flex-col ir-gap-1.5">
                            <span className="ir-text-xs ir-font-medium">¿Cómo quieres el informe?</span>
                            <textarea
                                value={full}
                                onChange={(event) => setFull(event.target.value)}
                                rows={7}
                                maxLength={4000}
                                placeholder="Ej.: Un informe mensual multipágina para el cliente: portada, resumen con las cifras clave, una página por área (seguridad, tráfico y SEO, ventas, mantenimiento) y cierre. Lenguaje sencillo, sin tecnicismos."
                                className="ir-w-full ir-resize-y ir-rounded-lg ir-border ir-border-input ir-bg-background ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed placeholder:ir-text-muted-foreground focus:ir-outline-none focus:ir-ring-2 focus:ir-ring-ring"
                            />
                        </label>
                        <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                            <span className="ir-self-center ir-text-[11px] ir-text-muted-foreground">Ideas:</span>
                            {EXAMPLES.map((example) => (
                                <button
                                    key={example.label}
                                    type="button"
                                    onClick={() => setFull(example.text)}
                                    className="ir-rounded-full ir-border ir-bg-background ir-px-2.5 ir-py-1 ir-text-[11px] ir-font-medium hover:ir-border-primary/40 hover:ir-bg-primary/5"
                                >
                                    {example.label}
                                </button>
                            ))}
                        </div>
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-border-t ir-pt-4">
                            <p className="ir-text-[11px] ir-text-muted-foreground">
                                {fullPending
                                    ? 'Armando el informe… con varias páginas puede tardar hasta un minuto.'
                                    : replacing
                                      ? 'Reemplaza lo que hay ahora en el lienzo.'
                                      : 'Sin descripción, arma un informe completo con todas las fuentes.'}
                            </p>
                            <Button onClick={() => onFull(full.trim())} disabled={!siteReady || pending}>
                                {fullPending ? <Loader2 className="ir-size-4 ir-animate-spin" /> : <Sparkles className="ir-size-4" />}
                                {fullPending ? 'Generando…' : 'Generar informe'}
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <label className="ir-flex ir-flex-col ir-gap-1.5">
                            <span className="ir-text-xs ir-font-medium">¿Qué sección quieres añadir?</span>
                            <textarea
                                value={section}
                                onChange={(event) => setSection(event.target.value)}
                                rows={4}
                                maxLength={2000}
                                placeholder="Ej.: una sección de rendimiento de anuncios con inversión, clics, conversiones y su evolución en el mes."
                                className="ir-w-full ir-resize-y ir-rounded-lg ir-border ir-border-input ir-bg-background ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed placeholder:ir-text-muted-foreground focus:ir-outline-none focus:ir-ring-2 focus:ir-ring-ring"
                            />
                        </label>
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-border-t ir-pt-4">
                            <p className="ir-text-[11px] ir-text-muted-foreground">Se añade debajo de lo que hay en la página actual.</p>
                            <Button onClick={() => onSection(section.trim())} disabled={!siteReady || pending || section.trim() === ''}>
                                {sectionPending ? <Loader2 className="ir-size-4 ir-animate-spin" /> : <Sparkles className="ir-size-4" />}
                                {sectionPending ? 'Generando…' : 'Añadir sección'}
                            </Button>
                        </div>
                    </div>
                )}

                {!siteReady && <p className="ir-mt-3 ir-text-xs ir-text-warning">Elige un sitio en la barra superior para generar con IA.</p>}
                {error !== null && (
                    <p role="alert" className="ir-mt-3 ir-rounded-lg ir-bg-danger/10 ir-px-3 ir-py-2 ir-text-xs ir-leading-relaxed ir-text-danger">
                        {error}
                    </p>
                )}
                <p className="ir-mt-4 ir-text-[11px] ir-text-muted-foreground">
                    ¿Prefieres partir de una plantilla? Pulsa <strong>«Plantillas»</strong> en la barra superior.
                </p>
            </div>
        </div>
    );
}
