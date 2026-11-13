import { Loader2, Sparkles, X } from 'lucide-react';
import { type ReactElement, useState } from 'react';

import { cn } from '@shared/lib/utils';

import { Button } from '../components/ui';
import { useElapsed } from './useElapsed';

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

/** Changes people ask for once a report exists. */
const CHANGES: { label: string; text: string }[] = [
    { label: 'Barras en vez de líneas', text: 'Cambia los gráficos de líneas y áreas por gráficos de barras.' },
    { label: 'Añadir una página', text: 'Añade una página de anuncios con la inversión, los clics, las conversiones y su evolución.' },
    { label: 'Títulos más claros', text: 'Reescribe los títulos para que un cliente sin conocimientos técnicos los entienda a la primera.' },
    { label: 'Lo importante primero', text: 'Pon las cifras más importantes al principio de cada página y deja el detalle al final.' },
    { label: 'Más compacto', text: 'Hazlo más corto: quita los bloques que repiten información y deja solo lo que más le interesa al cliente.' },
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
    adjustPending,
    error,
    onFull,
    onSection,
    onAdjust,
    barHidden = false,
    onShowBar,
}: {
    onClose: () => void;
    siteReady: boolean;
    /** The canvas already has content (the full report replaces it). */
    replacing: boolean;
    fullPending: boolean;
    sectionPending: boolean;
    adjustPending: boolean;
    error: string | null;
    onFull: (prompt: string) => void;
    onSection: (prompt: string) => void;
    onAdjust: (prompt: string) => void;
    /** The quick bar under the canvas was hidden: offer to bring it back. */
    barHidden?: boolean;
    onShowBar?: () => void;
}): ReactElement {
    // With a report on the canvas, asking for changes is the likely next step.
    const [mode, setMode] = useState<'full' | 'section' | 'adjust'>(replacing ? 'adjust' : 'full');
    const [full, setFull] = useState('');
    const [section, setSection] = useState('');
    const [change, setChange] = useState('');
    const pending = fullPending || sectionPending || adjustPending;
    const elapsed = useElapsed(pending);
    const working = `Trabajando… ${elapsed}. Un informe grande puede tardar 2 o 3 minutos; puedes cerrar esta ventana y seguir, se aplica solo al terminar.`;

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
                            ['adjust', 'Pedir cambios'],
                            ['section', 'Añadir una sección'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setMode(key)}
                            disabled={key === 'adjust' && !replacing}
                            title={key === 'adjust' && !replacing ? 'Primero genera un informe o elige una plantilla.' : undefined}
                            className={cn(
                                'ir-rounded-md ir-px-3 ir-py-1.5 ir-font-medium ir-transition-colors disabled:ir-cursor-not-allowed disabled:ir-opacity-40',
                                mode === key ? 'ir-bg-card ir-shadow-sm' : 'ir-text-muted-foreground hover:ir-text-foreground',
                            )}
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
                            <p className="ir-max-w-sm ir-text-[11px] ir-text-muted-foreground">
                                {fullPending
                                    ? working
                                    : replacing
                                      ? 'Reemplaza lo que hay ahora en el lienzo (podrás deshacerlo).'
                                      : 'Sin descripción, arma un informe completo con todas las fuentes.'}
                            </p>
                            <Button onClick={() => onFull(full.trim())} disabled={!siteReady || pending}>
                                {fullPending ? <Loader2 className="ir-size-4 ir-animate-spin" /> : <Sparkles className="ir-size-4" />}
                                {fullPending ? 'Generando…' : 'Generar informe'}
                            </Button>
                        </div>
                    </div>
                ) : mode === 'adjust' ? (
                    <div className="ir-flex ir-flex-col ir-gap-3">
                        <label className="ir-flex ir-flex-col ir-gap-1.5">
                            <span className="ir-text-xs ir-font-medium">¿Qué quieres cambiar del informe actual?</span>
                            <textarea
                                value={change}
                                onChange={(event) => setChange(event.target.value)}
                                rows={5}
                                maxLength={4000}
                                placeholder="Ej.: En la página de tráfico usa barras en vez de líneas, añade un mapa de países y quita la tabla de páginas. Mantén todo lo demás igual."
                                className="ir-w-full ir-resize-y ir-rounded-lg ir-border ir-border-input ir-bg-background ir-px-3 ir-py-2.5 ir-text-sm ir-leading-relaxed placeholder:ir-text-muted-foreground focus:ir-outline-none focus:ir-ring-2 focus:ir-ring-ring"
                            />
                        </label>
                        <div className="ir-flex ir-flex-wrap ir-gap-1.5">
                            <span className="ir-self-center ir-text-[11px] ir-text-muted-foreground">Ideas:</span>
                            {CHANGES.map((example) => (
                                <button
                                    key={example.label}
                                    type="button"
                                    onClick={() => setChange(example.text)}
                                    className="ir-rounded-full ir-border ir-bg-background ir-px-2.5 ir-py-1 ir-text-[11px] ir-font-medium hover:ir-border-primary/40 hover:ir-bg-primary/5"
                                >
                                    {example.label}
                                </button>
                            ))}
                        </div>
                        <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 ir-border-t ir-pt-4">
                            <p className="ir-max-w-sm ir-text-[11px] ir-text-muted-foreground">
                                {adjustPending ? working : 'Solo cambia lo que pidas; lo demás (y lo que ajustaste a mano) se queda igual. Podrás deshacerlo.'}
                            </p>
                            <Button onClick={() => onAdjust(change.trim())} disabled={!siteReady || pending || change.trim() === ''}>
                                {adjustPending ? <Loader2 className="ir-size-4 ir-animate-spin" /> : <Sparkles className="ir-size-4" />}
                                {adjustPending ? 'Aplicando…' : 'Aplicar cambios'}
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
                            <p className="ir-max-w-sm ir-text-[11px] ir-text-muted-foreground">{sectionPending ? working : 'Se añade debajo de lo que hay en la página actual.'}</p>
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
                <div className="ir-mt-4 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-2 ir-text-[11px] ir-text-muted-foreground">
                    <p>
                        ¿Prefieres partir de una plantilla? Pulsa <strong>«Plantillas»</strong> en la barra superior.
                    </p>
                    {barHidden && onShowBar !== undefined && (
                        <button type="button" onClick={onShowBar} className="ir-font-medium ir-text-accent hover:ir-underline">
                            Mostrar la barra de IA bajo el lienzo
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
