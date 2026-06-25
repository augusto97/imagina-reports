import {
    Clock,
    Database,
    DownloadCloud,
    FileBarChart,
    Globe,
    LayoutDashboard,
    LayoutTemplate,
    Lightbulb,
    LogOut,
    Menu,
    PanelLeftClose,
    PanelLeftOpen,
    PencilRuler,
    Settings,
    TrendingUp,
    Users,
    X,
} from "lucide-react";
import { type ReactElement, useEffect, useState } from "react";

import { cn } from "@shared/lib/utils";

import { useAuthUser, useLogout } from "./api";
import { EditorScreen } from "./editor/EditorScreen";
import { ClientsScreen } from "./screens/ClientsScreen";
import { DataSourcesScreen } from "./screens/DataSourcesScreen";
import { LoginScreen } from "./screens/LoginScreen";
import { ReportsScreen } from "./screens/ReportsScreen";
import { SettingsScreen } from "./screens/SettingsScreen";
import { SitesScreen } from "./screens/SitesScreen";
import { SystemScreen } from "./screens/SystemScreen";
import { TemplatesScreen } from "./screens/TemplatesScreen";
import { TrendsScreen } from "./screens/TrendsScreen";
import { UpsellScreen } from "./screens/UpsellScreen";
import { WorkLogsScreen } from "./screens/WorkLogsScreen";
import { type AdminView, useAdminUi, viewFromHash } from "./store";

const NAV: { view: AdminView; label: string; icon: typeof Users }[] = [
    { view: "clients", label: "Clientes", icon: Users },
    { view: "sites", label: "Sitios", icon: Globe },
    { view: "data-sources", label: "Fuentes", icon: Database },
    { view: "worklogs", label: "Trabajo", icon: Clock },
    { view: "editor", label: "Editor", icon: PencilRuler },
    { view: "templates", label: "Plantillas", icon: LayoutTemplate },
    { view: "reports", label: "Reportes", icon: FileBarChart },
    { view: "trends", label: "Tendencias", icon: TrendingUp },
    { view: "upsell", label: "Oportunidades", icon: Lightbulb },
    { view: "system", label: "Sistema", icon: DownloadCloud },
    { view: "settings", label: "Ajustes", icon: Settings },
];

function Screen({ view }: { view: AdminView }): ReactElement {
    switch (view) {
        case "clients":
            return <ClientsScreen />;
        case "sites":
            return <SitesScreen />;
        case "data-sources":
            return <DataSourcesScreen />;
        case "worklogs":
            return <WorkLogsScreen />;
        case "editor":
            return <EditorScreen />;
        case "templates":
            return <TemplatesScreen />;
        case "reports":
            return <ReportsScreen />;
        case "trends":
            return <TrendsScreen />;
        case "upsell":
            return <UpsellScreen />;
        case "system":
            return <SystemScreen />;
        case "settings":
            return <SettingsScreen />;
    }
}

export function App(): ReactElement {
    const { data: user, isLoading, isError } = useAuthUser();

    if (isLoading) {
        return (
            <div className="ir-flex ir-min-h-screen ir-items-center ir-justify-center ir-bg-background ir-text-sm ir-text-muted-foreground">
                Cargando…
            </div>
        );
    }

    if (isError || user === undefined) {
        return <LoginScreen />;
    }

    return <AuthenticatedApp email={user.email} version={user.app_version} />;
}

/** Reactive viewport match (no extra deps) — true while the media query holds. */
function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() =>
        typeof window !== "undefined" ? window.matchMedia(query).matches : false,
    );

    useEffect(() => {
        const mql = window.matchMedia(query);
        const handler = (event: MediaQueryListEvent): void => setMatches(event.matches);
        setMatches(mql.matches);
        mql.addEventListener("change", handler);

        return () => mql.removeEventListener("change", handler);
    }, [query]);

    return matches;
}

function AuthenticatedApp({ email, version }: { email: string; version?: string }): ReactElement {
    const view = useAdminUi((state) => state.view);
    const setView = useAdminUi((state) => state.setView);
    const logout = useLogout();

    // Desktop (lg+) shows a static, collapsible sidebar; below that it becomes an
    // off-canvas drawer toggled from the mobile top bar.
    const isDesktop = useMediaQuery("(min-width: 1024px)");
    const [mobileOpen, setMobileOpen] = useState(false);

    // Collapsible sidebar (Cloudflare/Linear style), persisted across reloads. The
    // collapsed (icon-only) treatment only applies on desktop; the mobile drawer always
    // shows full labels.
    const [collapsed, setCollapsed] = useState(() => localStorage.getItem("ir-sidebar-collapsed") === "1");
    const toggleCollapsed = (): void =>
        setCollapsed((current) => {
            const next = !current;
            localStorage.setItem("ir-sidebar-collapsed", next ? "1" : "0");
            return next;
        });
    const iconOnly = isDesktop && collapsed;

    // Keep the URL hash in sync with the active section so reloading restores it
    // (and the section is linkable). replaceState avoids spamming history; the hash
    // is read back into the store on the next load.
    useEffect(() => {
        const target = `#/${view}`;
        if (window.location.hash !== target) {
            window.history.replaceState(null, '', target);
        }
    }, [view]);

    // Honor manual hash edits and browser back/forward.
    useEffect(() => {
        const onHashChange = (): void => {
            const next = viewFromHash();
            if (next !== null) {
                setView(next);
            }
        };
        window.addEventListener('hashchange', onHashChange);

        return () => window.removeEventListener('hashchange', onHashChange);
    }, [setView]);

    // Navigating from the drawer also closes it (mobile only).
    const go = (next: AdminView): void => {
        setView(next);
        setMobileOpen(false);
    };

    // The editor is a full-bleed, full-height workspace (Figma/Looker style); every
    // other screen keeps the centered, max-width document layout.
    const fullBleed = view === "editor";

    return (
        <div className="ir-flex ir-h-screen ir-overflow-hidden ir-bg-background ir-text-foreground">
            {/* Mobile drawer backdrop. */}
            {mobileOpen && (
                <button
                    type="button"
                    aria-label="Cerrar menú"
                    onClick={() => setMobileOpen(false)}
                    className="ir-fixed ir-inset-0 ir-z-30 ir-bg-black/40 lg:ir-hidden"
                />
            )}

            <aside
                className={cn(
                    "ir-z-40 ir-flex ir-flex-col ir-overflow-y-auto ir-border-r ir-bg-card ir-py-4 ir-transition-all ir-duration-200",
                    // Mobile: fixed off-canvas drawer, slid in/out.
                    "ir-fixed ir-inset-y-0 ir-left-0 ir-w-64 ir-px-3 ir-shadow-xl",
                    mobileOpen ? "ir-translate-x-0" : "-ir-translate-x-full",
                    // Desktop: static in the flex row, collapsible, no shadow.
                    "lg:ir-static lg:ir-translate-x-0 lg:ir-shadow-none lg:ir-shrink-0",
                    iconOnly ? "lg:ir-w-16 lg:ir-px-2" : "lg:ir-w-56 lg:ir-px-3",
                )}
            >
                <div className={cn("ir-mb-5 ir-flex ir-items-center ir-px-1", iconOnly ? "lg:ir-justify-center" : "ir-gap-2.5 ir-px-2")}>
                    <span className="ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-primary ir-text-primary-foreground ir-shadow-ir-sm">
                        <LayoutDashboard className="ir-size-4" />
                    </span>
                    {!iconOnly && <span className="ir-text-sm ir-font-semibold ir-tracking-tight">Imagina Reports</span>}
                    {/* Drawer close (mobile only). */}
                    <button
                        type="button"
                        onClick={() => setMobileOpen(false)}
                        title="Cerrar menú"
                        className="ir-ml-auto ir-text-muted-foreground hover:ir-text-foreground lg:ir-hidden"
                    >
                        <X className="ir-size-5" />
                    </button>
                </div>

                {/* Collapse toggle is desktop-only (the drawer doesn't collapse). */}
                <button
                    type="button"
                    onClick={toggleCollapsed}
                    title={collapsed ? "Expandir menú" : "Colapsar menú"}
                    className={cn(
                        "ir-mb-3 ir-hidden ir-items-center ir-gap-2 ir-rounded-md ir-px-2.5 ir-py-1.5 ir-text-xs ir-text-muted-foreground ir-transition-colors hover:ir-bg-muted hover:ir-text-foreground lg:ir-flex",
                        iconOnly && "lg:ir-justify-center",
                    )}
                >
                    {iconOnly ? <PanelLeftOpen className="ir-size-4" /> : <PanelLeftClose className="ir-size-4" />}
                    {!iconOnly && "Colapsar"}
                </button>

                <nav className="ir-flex ir-flex-col ir-gap-0.5">
                    {NAV.map((item) => {
                        const active = view === item.view;

                        return (
                            <button
                                key={item.view}
                                type="button"
                                onClick={() => go(item.view)}
                                title={iconOnly ? item.label : undefined}
                                className={cn(
                                    "ir-group ir-flex ir-items-center ir-gap-2.5 ir-rounded-md ir-px-2.5 ir-py-2 ir-text-left ir-text-sm ir-transition-colors",
                                    iconOnly && "lg:ir-justify-center lg:ir-px-0",
                                    active
                                        ? "ir-bg-accent/10 ir-font-medium ir-text-accent"
                                        : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                                )}
                            >
                                <item.icon
                                    className={cn(
                                        "ir-size-4 ir-shrink-0",
                                        active ? "ir-text-accent" : "ir-text-muted-foreground group-hover:ir-text-foreground",
                                    )}
                                />
                                {!iconOnly && item.label}
                            </button>
                        );
                    })}
                </nav>

                <div className={cn("ir-mt-auto ir-border-t ir-pt-4 ir-text-xs ir-text-muted-foreground", iconOnly && "lg:ir-flex lg:ir-flex-col lg:ir-items-center")}>
                    {!iconOnly && (
                        <p className="ir-mb-2 ir-truncate" title={email}>
                            {email}
                        </p>
                    )}
                    <button
                        type="button"
                        onClick={() => logout.mutate()}
                        disabled={logout.isPending}
                        title="Cerrar sesión"
                        className={cn("ir-mb-3 ir-flex ir-items-center ir-gap-2 ir-text-left hover:ir-text-foreground", iconOnly && "lg:ir-justify-center")}
                    >
                        <LogOut className="ir-size-4" />
                        {!iconOnly && "Cerrar sesión"}
                    </button>
                    <button
                        type="button"
                        onClick={() => go("system")}
                        title="Versión instalada en este servidor — clic para ir a Sistema"
                        className={cn(
                            "ir-flex ir-items-center ir-gap-1.5 ir-rounded ir-bg-muted ir-px-2 ir-py-1 ir-font-mono ir-text-[11px] hover:ir-text-foreground",
                            iconOnly && "lg:ir-justify-center",
                        )}
                    >
                        <DownloadCloud className="ir-size-3" />
                        {!iconOnly && `v${(version ?? "—").replace(/^v/, "")}`}
                    </button>
                </div>
            </aside>

            <div className="ir-flex ir-min-w-0 ir-flex-1 ir-flex-col ir-overflow-hidden">
                {/* Mobile top bar with the drawer toggle (hidden on desktop). */}
                <header className="ir-flex ir-items-center ir-gap-3 ir-border-b ir-bg-card ir-px-4 ir-py-2.5 lg:ir-hidden">
                    <button
                        type="button"
                        onClick={() => setMobileOpen(true)}
                        title="Abrir menú"
                        className="ir-text-muted-foreground hover:ir-text-foreground"
                    >
                        <Menu className="ir-size-5" />
                    </button>
                    <span className="ir-flex ir-size-7 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-md ir-bg-primary ir-text-primary-foreground">
                        <LayoutDashboard className="ir-size-3.5" />
                    </span>
                    <span className="ir-text-sm ir-font-semibold ir-tracking-tight">Imagina Reports</span>
                </header>

                <main className="ir-min-w-0 ir-flex-1 ir-overflow-hidden">
                    {fullBleed ? (
                        <Screen view={view} />
                    ) : (
                        <div className="ir-h-full ir-overflow-y-auto">
                            <div className="ir-mx-auto ir-max-w-6xl ir-p-4 sm:ir-p-6 lg:ir-p-8">
                                <Screen view={view} />
                            </div>
                        </div>
                    )}
                </main>
            </div>
        </div>
    );
}
