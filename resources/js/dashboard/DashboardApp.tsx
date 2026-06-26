import { type ReactElement, useEffect, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { PasswordPrompt } from '@shared/components/PasswordPrompt';
import { applyBrandAccent, isPasswordRequired, isPrivate, usePublicDashboard } from '@shared/lib/publicReport';

const dayInput = (iso: string): string => iso.slice(0, 10);

/**
 * Live client dashboard (CLAUDE.md §11.2/Etapa D): a permanent, always-current view of a
 * published definition. The client changes the date range and the blocks re-resolve from
 * the latest stored snapshots (still §3.1 — never a live API). Same shared BlockList as
 * the report/portal, so it looks identical.
 */
export function DashboardApp({ token }: { token: string }): ReactElement {
    const [password, setPassword] = useState<string | undefined>(undefined);
    const [from, setFrom] = useState<string>('');
    const [to, setTo] = useState<string>('');

    const { data, isLoading, isError, error } = usePublicDashboard(token, { from, to, password });

    // Seed the pickers from the available range the first time data arrives.
    useEffect(() => {
        if (data !== undefined && from === '' && to === '') {
            setFrom(dayInput(data.period_start));
            setTo(dayInput(data.period_end));
        }
    }, [data, from, to]);

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

    const min = data.range !== null ? dayInput(data.range.start) : undefined;
    const max = data.range !== null ? dayInput(data.range.end) : undefined;

    return (
        <div className="ir-mx-auto ir-max-w-5xl ir-bg-card ir-p-4 ir-text-foreground sm:ir-p-8">
            <header className="ir-mb-6 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3">
                <div className="ir-flex ir-items-center ir-gap-3">
                    {data.agency?.logo_url != null && (
                        <img src={data.agency.logo_url} alt={data.agency.name} className="ir-h-8" />
                    )}
                    <span className="ir-text-xs ir-uppercase ir-tracking-wide ir-text-muted-foreground">
                        {data.agency?.name ?? 'Imagina Reports'}
                    </span>
                </div>

                <div className="ir-flex ir-items-center ir-gap-2 ir-text-sm">
                    <input
                        type="date"
                        value={from}
                        min={min}
                        max={to !== '' ? to : max}
                        onChange={(event) => setFrom(event.target.value)}
                        className="ir-rounded-md ir-border ir-bg-background ir-px-2 ir-py-1.5"
                    />
                    <span className="ir-text-muted-foreground">→</span>
                    <input
                        type="date"
                        value={to}
                        min={from !== '' ? from : min}
                        max={max}
                        onChange={(event) => setTo(event.target.value)}
                        className="ir-rounded-md ir-border ir-bg-background ir-px-2 ir-py-1.5"
                    />
                </div>
            </header>

            <BlockList blocks={data.blocks} data={data.data} context={data.context} currency={data.currency} locale={data.agency?.locale} theme={data.theme} />
        </div>
    );
}
