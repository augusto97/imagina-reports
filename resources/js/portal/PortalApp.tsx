import { CalendarDays, Download, Globe, Loader2, Mail, MessageCircle } from 'lucide-react';
import { type ReactElement, useEffect, useMemo, useState } from 'react';

import { BlockList } from '@shared/blocks/BlockRenderer';
import { PasswordPrompt } from '@shared/components/PasswordPrompt';
import { applyBrandAccent, downloadReportPdf, isPasswordRequired, isPrivate, periodLabel, type PublicReport, usePublicReport, useReportPeriods } from '@shared/lib/publicReport';

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function hostOf(url: string): string {
    try {
        return new URL(url).host.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/** Centered message for the states where there is no report to show. */
function Notice({ title, text }: { title: string; text: string }): ReactElement {
    return (
        <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-muted/40 ir-p-4">
            <div className="ir-w-full ir-max-w-sm ir-rounded-2xl ir-border ir-bg-card ir-p-6 ir-text-center ir-shadow-sm">
                <h1 className="ir-text-base ir-font-semibold">{title}</h1>
                <p className="ir-mt-1 ir-text-sm ir-text-muted-foreground">{text}</p>
            </div>
        </div>
    );
}

function Skeleton(): ReactElement {
    return (
        <div className="ir-min-h-screen ir-bg-muted/40">
            <div className="ir-h-14 ir-border-b ir-bg-card" />
            <div className="ir-mx-auto ir-flex ir-max-w-6xl ir-flex-col ir-gap-4 ir-px-4 ir-py-8 sm:ir-px-6">
                <div className="ir-h-24 ir-animate-pulse ir-rounded-2xl ir-bg-card" />
                <div className="ir-grid ir-gap-4 sm:ir-grid-cols-4">
                    {[0, 1, 2, 3].map((index) => (
                        <div key={index} className="ir-h-32 ir-animate-pulse ir-rounded-2xl ir-bg-card" />
                    ))}
                </div>
                <div className="ir-h-64 ir-animate-pulse ir-rounded-2xl ir-bg-card" />
            </div>
        </div>
    );
}

/** The agency's mark: its logo, or its initial on the brand colour. */
function AgencyMark({ report }: { report: PublicReport }): ReactElement {
    const name = report.agency?.name ?? '';
    const logo = report.agency?.logo_url ?? null;

    if (logo !== null && logo !== '') {
        return <img src={logo} alt={name} className="ir-h-7 ir-max-w-[9rem] ir-object-contain sm:ir-h-8" />;
    }

    return (
        <span className="ir-flex ir-items-center ir-gap-2">
            <span className="ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-lg ir-bg-primary ir-text-sm ir-font-bold ir-text-primary-foreground">
                {name.charAt(0).toUpperCase() || 'R'}
            </span>
            <span className="ir-hidden ir-text-sm ir-font-semibold sm:ir-inline">{name}</span>
        </span>
    );
}

const ACTION = 'ir-inline-flex ir-h-9 ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-lg ir-px-3 ir-text-sm ir-font-medium ir-transition-colors disabled:ir-opacity-60';

/**
 * The client's view of their report (CLAUDE.md §11.2): the agency's brand on top, the period
 * the way people say it, a month selector, the PDF and a way to reach the agency — around the
 * same BlockList the PDF prints.
 */
export function PortalApp({ token }: { token: string }): ReactElement {
    const [current, setCurrent] = useState(token);
    const [password, setPassword] = useState<string | undefined>(undefined);
    const [pdfState, setPdfState] = useState<'idle' | 'busy' | 'error'>('idle');
    const { data, isLoading, isError, error } = usePublicReport(current, { password });
    const { data: periods = [] } = useReportPeriods(token, { password });

    const locale = data?.agency?.locale?.replace('_', '-') ?? 'es';
    const label = data !== undefined ? capitalize(data.period_label ?? periodLabel(data.period_start, data.period_end, locale)) : '';

    // The selector lists the sent months; make sure the one on screen is always in it.
    const options = useMemo(() => {
        const list = periods.map((period) => ({ token: period.public_token, label: capitalize(periodLabel(period.period_start, period.period_end, locale)) }));
        if (data !== undefined && !list.some((item) => item.token === current)) {
            list.unshift({ token: current, label });
        }

        return list;
    }, [periods, data, current, label, locale]);

    useEffect(() => {
        // Per-report accent overrides the agency brand when set.
        applyBrandAccent(data?.theme?.accent ?? data?.agency?.brand_color);
        if (data !== undefined) {
            document.title = [data.site?.name, label, data.agency?.name].filter((part) => part != null && part !== '').join(' · ');
        }
    }, [data, label]);

    if (isPasswordRequired(error)) {
        return <PasswordPrompt onSubmit={setPassword} error={password !== undefined} />;
    }
    if (isPrivate(error)) {
        return <Notice title="Este informe es privado" text="No está disponible con este enlace. Pide a tu agencia que te lo comparta de nuevo." />;
    }
    if (isLoading) {
        return <Skeleton />;
    }
    if (isError || data === undefined) {
        return <Notice title="No encontramos este informe" text="El enlace puede estar incompleto o haber caducado. Pide a tu agencia uno nuevo." />;
    }

    const contact = data.agency?.contact ?? {};
    const whatsapp = contact.phone !== undefined ? `https://wa.me/${contact.phone.replace(/[^0-9]/g, '')}?text=${encodeURIComponent(`Hola, tengo una consulta sobre el informe de ${label} de ${data.site?.name ?? 'mi sitio'}.`)}` : null;
    const mail = contact.email !== undefined ? `mailto:${contact.email}?subject=${encodeURIComponent(`Consulta sobre el informe de ${label}`)}` : null;

    const downloadPdf = (): void => {
        setPdfState('busy');
        downloadReportPdf(current, password)
            .then(() => setPdfState('idle'))
            .catch(() => setPdfState('error'));
    };

    return (
        <div className="ir-min-h-screen ir-bg-muted/40 ir-text-foreground">
            <header className="ir-sticky ir-top-0 ir-z-20 ir-border-b ir-bg-card/90 ir-backdrop-blur print:ir-hidden">
                <div className="ir-mx-auto ir-flex ir-h-14 ir-max-w-6xl ir-items-center ir-gap-3 ir-px-4 sm:ir-h-16 sm:ir-px-6">
                    <AgencyMark report={data} />
                    <span className="ir-hidden ir-h-6 ir-w-px ir-bg-border sm:ir-block" aria-hidden />
                    <div className="ir-hidden ir-min-w-0 ir-flex-1 sm:ir-block">
                        <p className="ir-truncate ir-text-sm ir-font-semibold">{data.site?.name ?? data.context?.site}</p>
                        <p className="ir-truncate ir-text-xs ir-text-muted-foreground">Informe de {label.toLowerCase()}</p>
                    </div>
                    <div className="ir-ml-auto ir-flex ir-items-center ir-gap-1.5">
                        <button type="button" onClick={downloadPdf} disabled={pdfState === 'busy'} className={`${ACTION} ir-border ir-bg-card hover:ir-bg-muted`} title="Descargar el informe en PDF">
                            {pdfState === 'busy' ? <Loader2 className="ir-size-4 ir-animate-spin" /> : <Download className="ir-size-4" />}
                            <span className="ir-hidden sm:ir-inline">{pdfState === 'busy' ? 'Preparando…' : 'PDF'}</span>
                        </button>
                        {(whatsapp !== null || mail !== null) && (
                            <a href={whatsapp ?? mail ?? '#'} target={whatsapp !== null ? '_blank' : undefined} rel="noreferrer" className={`${ACTION} ir-bg-primary ir-text-primary-foreground hover:ir-opacity-90`}>
                                {whatsapp !== null ? <MessageCircle className="ir-size-4" /> : <Mail className="ir-size-4" />}
                                <span className="ir-hidden sm:ir-inline">Escríbenos</span>
                            </a>
                        )}
                    </div>
                </div>
            </header>

            <main className="ir-mx-auto ir-max-w-6xl ir-px-3 ir-py-5 sm:ir-px-6 sm:ir-py-8">
                <div className="ir-mb-4 ir-flex ir-flex-wrap ir-items-center ir-justify-between ir-gap-3 sm:ir-mb-6">
                    <div className="ir-min-w-0 sm:ir-hidden">
                        <p className="ir-truncate ir-text-base ir-font-semibold">{data.site?.name ?? data.context?.site}</p>
                        {data.site?.url !== undefined && <p className="ir-truncate ir-text-xs ir-text-muted-foreground">{hostOf(data.site.url)}</p>}
                    </div>
                    {data.site?.url !== undefined && (
                        <a href={data.site.url} target="_blank" rel="noreferrer" className="ir-hidden ir-items-center ir-gap-1.5 ir-text-sm ir-text-muted-foreground hover:ir-text-foreground sm:ir-inline-flex">
                            <Globe className="ir-size-4" />
                            {hostOf(data.site.url)}
                        </a>
                    )}
                    {options.length > 1 ? (
                        <label className="ir-relative ir-inline-flex ir-items-center">
                            <CalendarDays className="ir-pointer-events-none ir-absolute ir-left-3 ir-size-4 ir-text-muted-foreground" />
                            <span className="ir-sr-only">Periodo</span>
                            <select
                                className="ir-h-9 ir-appearance-none ir-rounded-lg ir-border ir-bg-card ir-pl-9 ir-pr-8 ir-text-sm ir-font-medium ir-shadow-sm focus:ir-outline-none focus:ir-ring-2 focus:ir-ring-primary/40"
                                value={current}
                                onChange={(event) => setCurrent(event.target.value)}
                            >
                                {options.map((option) => (
                                    <option key={option.token} value={option.token}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <span className="ir-pointer-events-none ir-absolute ir-right-3 ir-text-xs ir-text-muted-foreground">▾</span>
                        </label>
                    ) : (
                        <span className="ir-inline-flex ir-h-9 ir-items-center ir-gap-2 ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-text-sm ir-font-medium ir-shadow-sm">
                            <CalendarDays className="ir-size-4 ir-text-muted-foreground" />
                            {label}
                        </span>
                    )}
                </div>

                {pdfState === 'error' && (
                    <p role="alert" className="ir-mb-4 ir-rounded-lg ir-bg-danger/10 ir-px-3 ir-py-2 ir-text-sm ir-text-danger">
                        No pudimos preparar el PDF ahora mismo. Inténtalo de nuevo en unos minutos.
                    </p>
                )}

                <BlockList blocks={data.blocks} data={data.data} context={data.context} currency={data.currency} locale={data.agency?.locale} theme={data.theme} pages={data.pages} agency={data.agency} mode="paged" />

                <footer className="ir-mt-8 ir-flex ir-flex-col ir-items-center ir-justify-between ir-gap-3 ir-border-t ir-pt-6 ir-text-sm ir-text-muted-foreground sm:ir-flex-row">
                    <p>
                        Informe preparado por <span className="ir-font-medium ir-text-foreground">{data.agency?.name}</span>
                    </p>
                    <div className="ir-flex ir-flex-wrap ir-items-center ir-justify-center ir-gap-x-4 ir-gap-y-1">
                        {contact.email !== undefined && (
                            <a href={mail ?? '#'} className="ir-inline-flex ir-items-center ir-gap-1.5 hover:ir-text-foreground">
                                <Mail className="ir-size-3.5" />
                                {contact.email}
                            </a>
                        )}
                        {whatsapp !== null && (
                            <a href={whatsapp} target="_blank" rel="noreferrer" className="ir-inline-flex ir-items-center ir-gap-1.5 hover:ir-text-foreground">
                                <MessageCircle className="ir-size-3.5" />
                                WhatsApp
                            </a>
                        )}
                        {contact.website !== undefined && (
                            <a href={contact.website} target="_blank" rel="noreferrer" className="ir-inline-flex ir-items-center ir-gap-1.5 hover:ir-text-foreground">
                                <Globe className="ir-size-3.5" />
                                {hostOf(contact.website)}
                            </a>
                        )}
                        {data.show_branding === true && <span className="ir-text-xs ir-text-muted-foreground/70">Reportes con Imagina Reports</span>}
                    </div>
                </footer>
            </main>
        </div>
    );
}
