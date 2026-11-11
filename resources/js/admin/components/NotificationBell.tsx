import { Bell, CheckCheck, CheckCircle2, Plug, Settings2, Trash2, Unplug, type LucideIcon } from 'lucide-react';
import { type ReactElement, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { cn } from '@shared/lib/utils';

import { useMarkNotificationsRead, useNotifications } from '../api';
import { useAdminUi } from '../store';
import type { AppNotice } from '../types';
import { noticeAge, noticeTarget } from './notices';

const KIND: Record<string, { icon: LucideIcon; tone: string }> = {
    source_connected: { icon: Plug, tone: 'ir-bg-info/10 ir-text-info' },
    source_failed: { icon: Unplug, tone: 'ir-bg-danger/10 ir-text-danger' },
    source_recovered: { icon: CheckCircle2, tone: 'ir-bg-success/10 ir-text-success' },
    source_removed: { icon: Trash2, tone: 'ir-bg-muted ir-text-muted-foreground' },
};

/**
 * The bell in the sidebar: what happened to the clients' connections (connected, stopped
 * working, back, removed) for the sites this person follows. Opens a panel beside the sidebar;
 * a notice takes you to that site's connections.
 */
export function NotificationBell({
    iconOnly,
    panelLeft,
    onOpenSettings,
    onNavigate,
}: {
    iconOnly: boolean;
    panelLeft: string;
    onOpenSettings: () => void;
    /** Called after a notice takes you somewhere (closes the phone drawer). */
    onNavigate?: () => void;
}): ReactElement {
    const [open, setOpen] = useState(false);
    const { data } = useNotifications();
    const markRead = useMarkNotificationsRead();
    const selectSite = useAdminUi((state) => state.selectSite);
    const panel = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const unread = data?.unread ?? 0;
    const notices = data?.data ?? [];

    useEffect(() => {
        if (!open) return;
        const close = (event: MouseEvent): void => {
            const target = event.target as Node;
            if (panel.current?.contains(target) || trigger.current?.contains(target)) return;
            setOpen(false);
        };
        const escape = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const openNotice = (notice: AppNotice): void => {
        if (!notice.read) markRead.mutate([notice.id]);
        const { site, reconnect } = noticeTarget(notice);
        if (site !== null) {
            selectSite(site);
            if (reconnect !== null) useAdminUi.setState({ reconnectFocus: reconnect });
        }
        setOpen(false);
        onNavigate?.();
    };

    return (
        <>
            <button
                ref={trigger}
                type="button"
                onClick={() => setOpen((current) => !current)}
                title={iconOnly ? 'Avisos' : undefined}
                aria-label={unread > 0 ? `Avisos: ${unread} sin leer` : 'Avisos'}
                aria-expanded={open}
                className={cn(
                    'ir-group ir-relative ir-mb-1 ir-flex ir-w-full ir-items-center ir-gap-2.5 ir-rounded-lg ir-px-2.5 ir-py-[7px] ir-text-left ir-text-sm ir-transition-colors',
                    iconOnly && 'lg:ir-justify-center lg:ir-px-0',
                    open ? 'ir-bg-card ir-font-medium ir-text-foreground ir-shadow-ir-xs ir-ring-1 ir-ring-border' : 'ir-text-muted-foreground hover:ir-bg-card/70 hover:ir-text-foreground',
                )}
            >
                <Bell className={cn('ir-size-4 ir-shrink-0', open ? 'ir-text-accent' : 'ir-text-muted-foreground group-hover:ir-text-foreground')} />
                {!iconOnly && <span className="ir-flex-1">Avisos</span>}
                {unread > 0 && (
                    <span
                        className={cn(
                            'ir-rounded-full ir-bg-danger ir-px-1.5 ir-text-[10px] ir-font-semibold ir-leading-4 ir-text-white ir-tabular-nums',
                            iconOnly && 'lg:ir-absolute lg:ir-right-1 lg:ir-top-0.5',
                        )}
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {/* Portalled to <body>: the sidebar slides with a CSS transform, which would trap a
                fixed panel inside it (and its scroll clipping). */}
            {open && createPortal(
                <div
                    ref={panel}
                    role="dialog"
                    aria-label="Avisos"
                    style={{ ['--ir-bell-left' as string]: panelLeft }}
                    className="ir-fixed ir-inset-x-3 ir-bottom-3 ir-z-50 ir-flex ir-max-h-[75vh] ir-flex-col ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-lg sm:ir-inset-x-auto sm:ir-left-[var(--ir-bell-left)] sm:ir-w-[380px]"
                >
                    <div className="ir-flex ir-items-center ir-gap-2 ir-border-b ir-px-4 ir-py-3">
                        <p className="ir-flex-1 ir-text-sm ir-font-semibold">Avisos</p>
                        {unread > 0 && (
                            <button
                                type="button"
                                onClick={() => markRead.mutate(undefined)}
                                className="ir-inline-flex ir-items-center ir-gap-1 ir-rounded-md ir-px-2 ir-py-1 ir-text-xs ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                            >
                                <CheckCheck className="ir-size-3.5" />
                                Marcar todo como leído
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => {
                                setOpen(false);
                                onOpenSettings();
                            }}
                            title="Elegir qué avisos te llegan"
                            aria-label="Elegir qué avisos te llegan"
                            className="ir-rounded-md ir-p-1.5 ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground"
                        >
                            <Settings2 className="ir-size-4" />
                        </button>
                    </div>

                    <div className="ir-min-h-0 ir-flex-1 ir-overflow-y-auto">
                        {notices.length === 0 ? (
                            <div className="ir-flex ir-flex-col ir-items-center ir-gap-2 ir-px-6 ir-py-10 ir-text-center">
                                <span className="ir-flex ir-size-10 ir-items-center ir-justify-center ir-rounded-full ir-bg-muted ir-text-muted-foreground">
                                    <Bell className="ir-size-5" />
                                </span>
                                <p className="ir-text-sm ir-font-medium">Todo tranquilo</p>
                                <p className="ir-text-xs ir-leading-relaxed ir-text-muted-foreground">
                                    Aquí verás cuándo alguien conecta o quita una fuente, y cuándo una conexión deja de funcionar o vuelve a hacerlo.
                                </p>
                            </div>
                        ) : (
                            <ul className="ir-divide-y">
                                {notices.map((notice) => {
                                    const kind = KIND[notice.kind] ?? KIND.source_connected;
                                    const Icon = kind?.icon ?? Bell;

                                    return (
                                        <li key={notice.id}>
                                            <button
                                                type="button"
                                                onClick={() => openNotice(notice)}
                                                className={cn('ir-flex ir-w-full ir-items-start ir-gap-3 ir-px-4 ir-py-3 ir-text-left ir-transition-colors hover:ir-bg-muted/60', !notice.read && 'ir-bg-accent/[0.04]')}
                                            >
                                                <span className={cn('ir-mt-0.5 ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full', kind?.tone)}>
                                                    <Icon className="ir-size-3.5" />
                                                </span>
                                                <span className="ir-min-w-0 ir-flex-1">
                                                    <span className={cn('ir-block ir-text-sm ir-leading-snug', notice.read ? 'ir-text-foreground/80' : 'ir-font-medium ir-text-foreground')}>
                                                        {notice.title}
                                                    </span>
                                                    {notice.body !== null && notice.body !== '' && (
                                                        <span className="ir-mt-0.5 ir-line-clamp-2 ir-block ir-text-xs ir-leading-relaxed ir-text-muted-foreground">{notice.body}</span>
                                                    )}
                                                    <span className="ir-mt-1 ir-block ir-text-[11px] ir-text-muted-foreground/80">{noticeAge(notice.created_at)}</span>
                                                </span>
                                                {!notice.read && <span className="ir-mt-2 ir-size-2 ir-shrink-0 ir-rounded-full ir-bg-accent" aria-label="Sin leer" />}
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                </div>,
                document.body,
            )}
        </>
    );
}
