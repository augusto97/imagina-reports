import { ArrowUp, ChevronDown, Loader2, SlidersHorizontal, Sparkles } from 'lucide-react';
import { type FormEvent, type ReactElement, useEffect, useRef, useState } from 'react';

import { cn } from '@shared/lib/utils';

import { useElapsed } from './useElapsed';

/**
 * The AI, always at hand under the canvas: type a change («agrega un gráfico de ventas por día»)
 * and press Enter. With a report on the canvas it asks for changes; on an empty one it builds the
 * report. «Más opciones» opens the full window (whole report, add a section, ideas). It can be
 * hidden for good (the «IA» button on the top bar stays, and its window can bring the bar back).
 */
export function AiCommandBar({
    siteReady,
    hasReport,
    pending,
    error,
    onSubmit,
    onMore,
    onHide,
    compact = false,
}: {
    siteReady: boolean;
    /** The canvas already has a report: the request is a change to it. */
    hasReport: boolean;
    pending: boolean;
    error: string | null;
    onSubmit: (text: string) => void;
    onMore: () => void;
    onHide: () => void;
    compact?: boolean;
}): ReactElement {
    const [text, setText] = useState('');
    const sent = useRef(false);
    const elapsed = useElapsed(pending);

    // Clear the request once the AI applied it; keep it when it failed, to retry or reword.
    useEffect(() => {
        if (!pending && sent.current) {
            sent.current = false;
            if (error === null) {
                setText('');
            }
        }
    }, [pending, error]);

    const submit = (event: FormEvent): void => {
        event.preventDefault();
        const request = text.trim();
        if (request === '' || !siteReady || pending) {
            return;
        }
        sent.current = true;
        onSubmit(request);
    };

    const placeholder = !siteReady
        ? compact
            ? 'Elige un sitio para usar la IA'
            : 'Elige un sitio arriba para pedirle cambios a la IA'
        : hasReport
          ? compact
              ? 'Pídele un cambio a la IA…'
              : 'Pídele un cambio a la IA: «agrega un gráfico de ventas por día en esta página», «quita la tabla de páginas»…'
          : 'Describe el informe que quieres y la IA lo arma con tus datos…';

    return (
        <div className="ir-shrink-0 ir-border-t ir-bg-background/80 ir-px-3 ir-py-2 ir-backdrop-blur lg:ir-px-6">
            <form onSubmit={submit} className="ir-mx-auto ir-flex ir-max-w-3xl ir-items-center ir-gap-2">
                <div
                    className={cn(
                        'ir-flex ir-min-w-0 ir-flex-1 ir-items-center ir-gap-2 ir-rounded-full ir-border ir-bg-card ir-py-1 ir-pl-3.5 ir-pr-1 ir-shadow-ir-xs ir-transition-colors',
                        'ir-border-accent/30 focus-within:ir-border-accent focus-within:ir-ring-2 focus-within:ir-ring-accent/20',
                        pending && 'ir-border-accent/60',
                    )}
                >
                    {pending ? (
                        <Loader2 className="ir-size-4 ir-shrink-0 ir-animate-spin ir-text-accent" />
                    ) : (
                        <Sparkles className="ir-size-4 ir-shrink-0 ir-text-accent" />
                    )}
                    {pending ? (
                        <p className="ir-min-w-0 ir-flex-1 ir-truncate ir-py-1.5 ir-text-sm ir-text-muted-foreground">
                            La IA está trabajando… {elapsed}
                            <span className="ir-hidden sm:ir-inline"> · puedes seguir mirando, el cambio se aplica solo al terminar</span>
                        </p>
                    ) : (
                        <input
                            value={text}
                            onChange={(event) => setText(event.target.value)}
                            disabled={!siteReady}
                            maxLength={4000}
                            placeholder={placeholder}
                            aria-label="Pedirle a la IA"
                            className="ir-min-w-0 ir-flex-1 ir-bg-transparent ir-py-1.5 ir-text-sm placeholder:ir-text-muted-foreground focus:ir-outline-none disabled:ir-cursor-not-allowed"
                        />
                    )}
                    <button
                        type="submit"
                        disabled={!siteReady || pending || text.trim() === ''}
                        title={hasReport ? 'Aplicar el cambio' : 'Generar el informe'}
                        aria-label={hasReport ? 'Aplicar el cambio' : 'Generar el informe'}
                        className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-bg-accent ir-text-accent-foreground ir-transition-opacity hover:ir-opacity-90 disabled:ir-opacity-30"
                    >
                        <ArrowUp className="ir-size-4" />
                    </button>
                </div>
                <button
                    type="button"
                    onClick={onMore}
                    title="Informe completo, añadir una sección e ideas"
                    className="ir-flex ir-shrink-0 ir-items-center ir-gap-1.5 ir-rounded-full ir-border ir-bg-card ir-px-3 ir-py-2 ir-text-xs ir-font-medium ir-text-muted-foreground ir-shadow-ir-xs hover:ir-bg-muted hover:ir-text-foreground"
                >
                    <SlidersHorizontal className="ir-size-3.5" />
                    <span className={cn(compact && 'ir-sr-only')}>Más opciones</span>
                </button>
                <button
                    type="button"
                    onClick={onHide}
                    title="Ocultar esta barra. La IA sigue en el botón «IA» de arriba, y desde ahí puedes volver a mostrarla."
                    aria-label="Ocultar la barra de IA"
                    className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                >
                    <ChevronDown className="ir-size-4" />
                </button>
            </form>
        </div>
    );
}
