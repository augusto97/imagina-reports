import { type ReactElement, useEffect, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { PasswordPrompt } from '@shared/components/PasswordPrompt';
import { applyBrandAccent, isPasswordRequired, isPrivate, usePublicReport } from '@shared/lib/publicReport';

/**
 * The public report page (CLAUDE.md §11.2/§11.4). Fetches the frozen resolved
 * blocks by public token and renders them with the shared BlockList — the very
 * same view Browsershot prints to PDF. Sets `window.reportReady` when done.
 */
export function ReportApp({ token, printToken }: { token: string; printToken?: string }): ReactElement {
    const [password, setPassword] = useState<string | undefined>(undefined);
    const { data, isLoading, isError, error } = usePublicReport(token, { printToken, password });

    useEffect(() => {
        applyBrandAccent(data?.theme?.accent ?? data?.agency?.brand_color);

        if (data === undefined && !isError) {
            return;
        }

        // Signal the PDF renderer only once everything a client would see has actually
        // painted: web fonts loaded, all images (logos, screenshots) settled, then two
        // animation frames so Recharts' SVG is on screen. Setting it immediately (as before)
        // let Browsershot capture blank charts / unstyled text / missing logos.
        let cancelled = false;
        const fonts = document.fonts?.ready ?? Promise.resolve();
        const images = Array.from(document.images)
            .filter((img) => !img.complete)
            .map((img) => new Promise<void>((resolve) => {
                img.addEventListener('load', () => resolve(), { once: true });
                img.addEventListener('error', () => resolve(), { once: true });
            }));

        void Promise.all([fonts, ...images]).then(() => {
            if (cancelled) {
                return;
            }
            requestAnimationFrame(() => requestAnimationFrame(() => {
                window.reportReady = true;
            }));
        });

        return () => {
            cancelled = true;
        };
    }, [data, isError]);

    if (isPasswordRequired(error)) {
        return <PasswordPrompt onSubmit={setPassword} error={password !== undefined} />;
    }

    if (isPrivate(error)) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Este informe es privado y no está disponible.</div>;
    }

    if (isLoading) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Cargando…</div>;
    }

    if (isError || data === undefined) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Reporte no disponible.</div>;
    }

    return (
        <div className="ir-mx-auto ir-max-w-5xl ir-bg-muted/30 ir-p-4 ir-text-foreground print:ir-bg-transparent print:ir-p-0 sm:ir-p-8">
            {/* On-screen wordmark above the report surface. Hidden in the PDF — the report's
                own cover block already carries the logo, so printing this too duplicates it. */}
            {data.agency !== null && (
                <div className="ir-mb-6 ir-flex ir-items-center ir-gap-3 print:ir-hidden">
                    {data.agency.logo_url !== null && (
                        <img src={data.agency.logo_url} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency.name}
                    </span>
                </div>
            )}
            <BlockList
                blocks={data.blocks}
                data={data.data}
                context={data.context}
                currency={data.currency}
                locale={data.agency?.locale}
                theme={data.theme}
                pages={data.pages}
                agency={data.agency}
                // Browsershot carries the print token → stack every page as its own PDF sheet;
                // a human viewing the link gets the interactive one-page-at-a-time navigation.
                mode={printToken != null && printToken !== '' ? 'print' : 'paged'}
            />
        </div>
    );
}
