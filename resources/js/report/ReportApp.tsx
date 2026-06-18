import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import type { Block } from '@shared/blocks/types';
import { api } from '@shared/lib/api';
import { hexToHslString } from '@shared/lib/color';

interface PublicReport {
    period_start: string;
    period_end: string;
    health_score: number | null;
    status: string;
    blocks: Block[];
    data: Record<string, unknown>;
    agency: { name: string; brand_color: string | null; logo_path: string | null; locale: string } | null;
}

/**
 * The public report page (CLAUDE.md §11.2/§11.4). Fetches the frozen resolved
 * blocks by public token and renders them with the shared BlockList — the very
 * same view Browsershot prints to PDF. Sets `window.reportReady` when done.
 */
export function ReportApp({ token }: { token: string }): React.ReactElement {
    const { data, isLoading, isError } = useQuery<PublicReport>({
        queryKey: ['public-report', token],
        queryFn: async () => {
            const response = await api.get<PublicReport>(`/public/reports/${token}`);

            return response.data;
        },
    });

    useEffect(() => {
        // White-label: apply the agency's brand colour as the accent (CLAUDE.md §11.5).
        const brand = data?.agency?.brand_color;
        if (typeof brand === 'string') {
            const hsl = hexToHslString(brand);
            if (hsl !== null) {
                document.documentElement.style.setProperty('--ir-primary', hsl);
                document.documentElement.style.setProperty('--ir-ring', hsl);
            }
        }

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
        <div className="ir-mx-auto ir-max-w-3xl ir-bg-background ir-p-8 ir-text-foreground">
            {data.agency !== null && (
                <div className="ir-mb-6 ir-flex ir-items-center ir-gap-3">
                    {data.agency.logo_path !== null && (
                        <img src={data.agency.logo_path} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency.name}
                    </span>
                </div>
            )}
            <BlockList blocks={data.blocks} data={data.data} />
        </div>
    );
}
