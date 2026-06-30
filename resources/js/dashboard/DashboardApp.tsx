import { type ReactElement, useEffect, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { PasswordPrompt } from '@shared/components/PasswordPrompt';
import { applyBrandAccent, isPasswordRequired, isPrivate, usePublicDashboard } from '@shared/lib/publicReport';

/**
 * Live client dashboard (CLAUDE.md §11.2/Etapa D): a permanent, always-current view of a
 * published definition. The client picks one of the periods that actually have data and the
 * blocks re-resolve from that period's stored snapshot (still §3.1 — never a live API). Same
 * shared BlockList as the report/portal, so it looks identical.
 *
 * A snapshot is a pre-aggregated total for a whole sync period, so the client selects WHOLE
 * periods (not arbitrary day ranges): there's nothing between snapshots, and a total can't be
 * re-sliced to a sub-range. The dropdown therefore lists only windows with data.
 */
export function DashboardApp({ token }: { token: string }): ReactElement {
    const [password, setPassword] = useState<string | undefined>(undefined);
    // The selected period as `${startIso}|${endIso}`. Empty until the first response, then
    // seeded to the active (latest) period.
    const [periodKey, setPeriodKey] = useState<string>('');
    const [from, to] = periodKey !== '' ? periodKey.split('|') : ['', ''];

    const { data, isLoading, isError, error } = usePublicDashboard(token, { from, to, password });

    // Once data arrives, lock the selector onto the active period.
    useEffect(() => {
        if (data !== undefined && periodKey === '') {
            setPeriodKey(`${data.period_start}|${data.period_end}`);
        }
    }, [data, periodKey]);

    useEffect(() => {
        applyBrandAccent(data?.theme?.accent ?? data?.agency?.brand_color);
    }, [data]);

    if (isPasswordRequired(error)) {
        return <PasswordPrompt onSubmit={setPassword} error={password !== undefined} />;
    }

    if (isPrivate(error)) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Este panel es privado y no está disponible.</div>;
    }

    if (isLoading && data === undefined) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Cargando…</div>;
    }

    if (isError || data === undefined) {
        return <div className="ir-p-8 ir-text-sm ir-text-muted-foreground">Panel no disponible.</div>;
    }

    const activeKey = `${data.period_start}|${data.period_end}`;

    return (
        <div className="ir-mx-auto ir-max-w-5xl ir-bg-muted/30 ir-p-4 ir-text-foreground sm:ir-p-8">
            <header className="ir-mb-6 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                <div className="ir-flex ir-items-center ir-gap-3">
                    {data.agency?.logo_url != null && (
                        <img src={data.agency.logo_url} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency?.name ?? 'Imagina Reports'}
                    </span>
                </div>

                {data.periods.length > 0 && (
                    <div className="ir-flex ir-items-center ir-gap-2 ir-text-sm">
                        <span className="ir-text-xs ir-text-muted-foreground">Periodo</span>
                        <select
                            value={periodKey !== '' ? periodKey : activeKey}
                            onChange={(event) => setPeriodKey(event.target.value)}
                            className="ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-1.5"
                        >
                            {data.periods.map((period) => (
                                <option key={`${period.start}|${period.end}`} value={`${period.start}|${period.end}`}>
                                    {period.label}
                                </option>
                            ))}
                        </select>
                    </div>
                )}
            </header>

            <BlockList blocks={data.blocks} data={data.data} context={data.context} currency={data.currency} locale={data.agency?.locale} theme={data.theme} pages={data.pages} agency={data.agency} mode="paged" />
        </div>
    );
}
