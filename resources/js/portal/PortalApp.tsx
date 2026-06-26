import { type ReactElement, useEffect, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { PasswordPrompt } from '@shared/components/PasswordPrompt';
import { applyBrandAccent, isPasswordRequired, isPrivate, usePublicReport, useReportPeriods } from '@shared/lib/publicReport';

function formatPeriod(start: string, end: string): string {
    return `${start.slice(0, 10)} → ${end.slice(0, 10)}`;
}

/**
 * Interactive client portal (CLAUDE.md §11.2): opened via the signed public token,
 * with a period selector that switches between the definition's reports. Renders
 * the same blocks the report page does, via the shared BlockList (interactive charts).
 */
export function PortalApp({ token }: { token: string }): ReactElement {
    const [current, setCurrent] = useState(token);
    const [password, setPassword] = useState<string | undefined>(undefined);
    const { data, isLoading, isError, error } = usePublicReport(current, { password });
    const { data: periods = [] } = useReportPeriods(token, { password });

    useEffect(() => {
        // Per-report accent overrides the agency brand when set.
        applyBrandAccent(data?.theme?.accent ?? data?.agency?.brand_color);
    }, [data]);

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
        <div className="ir-mx-auto ir-max-w-5xl ir-bg-muted/30 ir-p-4 ir-text-foreground sm:ir-p-8">
            <header className="ir-mb-6 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                <div className="ir-flex ir-items-center ir-gap-3">
                    {data.agency?.logo_path != null && (
                        <img src={data.agency.logo_path} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency?.name ?? 'Imagina Reports'}
                    </span>
                </div>

                {periods.length > 1 && (
                    <select
                        className="ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm"
                        value={current}
                        onChange={(event) => setCurrent(event.target.value)}
                    >
                        {periods.map((period) => (
                            <option key={period.public_token} value={period.public_token}>
                                {formatPeriod(period.period_start, period.period_end)}
                            </option>
                        ))}
                    </select>
                )}
            </header>

            <BlockList blocks={data.blocks} data={data.data} context={data.context} currency={data.currency} locale={data.agency?.locale} theme={data.theme} pages={data.pages} agency={data.agency} mode="paged" />
        </div>
    );
}
