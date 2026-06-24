import { type ReactElement, useEffect } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { applyBrandAccent, usePublicReport } from '@shared/lib/publicReport';

/**
 * The public report page (CLAUDE.md §11.2/§11.4). Fetches the frozen resolved
 * blocks by public token and renders them with the shared BlockList — the very
 * same view Browsershot prints to PDF. Sets `window.reportReady` when done.
 */
export function ReportApp({ token }: { token: string }): ReactElement {
    const { data, isLoading, isError } = usePublicReport(token);

    useEffect(() => {
        applyBrandAccent(data?.theme?.accent ?? data?.agency?.brand_color);

        if (data !== undefined || isError) {
            // Signal the PDF renderer that the page is fully painted (success or empty).
            window.reportReady = true;
        }
    }, [data, isError]);

    if (isLoading) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Cargando…</div>;
    }

    if (isError || data === undefined) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Reporte no disponible.</div>;
    }

    return (
        <div className="ir-mx-auto ir-max-w-5xl ir-bg-card ir-p-4 ir-text-foreground sm:ir-p-8">
            {data.agency !== null && (
                <div className="ir-mb-6 ir-flex ir-items-center ir-gap-3">
                    {data.agency.logo_url !== null && (
                        <img src={data.agency.logo_url} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency.name}
                    </span>
                </div>
            )}
            <BlockList blocks={data.blocks} data={data.data} context={data.context} currency={data.currency} locale={data.agency?.locale} theme={data.theme} />
        </div>
    );
}
