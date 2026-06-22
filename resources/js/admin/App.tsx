import {
    Clock,
    Database,
    DownloadCloud,
    FileBarChart,
    Globe,
    LayoutDashboard,
    LayoutTemplate,
    LogOut,
    PencilRuler,
    Settings,
    TrendingUp,
    Users,
} from "lucide-react";
import { type ReactElement } from "react";

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
import { WorkLogsScreen } from "./screens/WorkLogsScreen";
import { type AdminView, useAdminUi } from "./store";

const NAV: { view: AdminView; label: string; icon: typeof Users }[] = [
    { view: "clients", label: "Clientes", icon: Users },
    { view: "sites", label: "Sitios", icon: Globe },
    { view: "data-sources", label: "Fuentes", icon: Database },
    { view: "worklogs", label: "Trabajo", icon: Clock },
    { view: "editor", label: "Editor", icon: PencilRuler },
    { view: "templates", label: "Plantillas", icon: LayoutTemplate },
    { view: "reports", label: "Reportes", icon: FileBarChart },
    { view: "trends", label: "Tendencias", icon: TrendingUp },
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

function AuthenticatedApp({ email, version }: { email: string; version?: string }): ReactElement {
    const view = useAdminUi((state) => state.view);
    const setView = useAdminUi((state) => state.setView);
    const logout = useLogout();

    // The editor is a full-bleed, full-height workspace (Figma/Looker style); every
    // other screen keeps the centered, max-width document layout.
    const fullBleed = view === "editor";

    return (
        <div className="ir-flex ir-h-screen ir-overflow-hidden ir-bg-background ir-text-foreground">
            <aside className="ir-flex ir-w-56 ir-shrink-0 ir-flex-col ir-overflow-y-auto ir-border-r ir-bg-card ir-px-3 ir-py-4">
                <div className="ir-mb-5 ir-flex ir-items-center ir-gap-2.5 ir-px-2">
                    <span className="ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-lg ir-bg-primary ir-text-primary-foreground ir-shadow-ir-sm">
                        <LayoutDashboard className="ir-size-4" />
                    </span>
                    <span className="ir-text-sm ir-font-semibold ir-tracking-tight">Imagina Reports</span>
                </div>
                <nav className="ir-flex ir-flex-col ir-gap-0.5">
                    {NAV.map((item) => {
                        const active = view === item.view;

                        return (
                            <button
                                key={item.view}
                                type="button"
                                onClick={() => setView(item.view)}
                                className={cn(
                                    "ir-group ir-flex ir-items-center ir-gap-2.5 ir-rounded-lg ir-px-2.5 ir-py-2 ir-text-left ir-text-sm ir-transition-colors",
                                    active
                                        ? "ir-bg-accent/10 ir-font-medium ir-text-accent"
                                        : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                                )}
                            >
                                <item.icon
                                    className={cn(
                                        "ir-size-4",
                                        active ? "ir-text-accent" : "ir-text-muted-foreground group-hover:ir-text-foreground",
                                    )}
                                />
                                {item.label}
                            </button>
                        );
                    })}
                </nav>
                <div className="ir-mt-auto ir-border-t ir-pt-4 ir-text-xs ir-text-muted-foreground">
                    <p className="ir-mb-2 ir-truncate" title={email}>
                        {email}
                    </p>
                    <button
                        type="button"
                        onClick={() => logout.mutate()}
                        disabled={logout.isPending}
                        className="ir-mb-3 ir-flex ir-items-center ir-gap-2 ir-text-left hover:ir-text-foreground"
                    >
                        <LogOut className="ir-size-4" />
                        Cerrar sesión
                    </button>
                    <button
                        type="button"
                        onClick={() => setView('system')}
                        title="Versión instalada en este servidor — clic para ir a Sistema"
                        className="ir-flex ir-items-center ir-gap-1.5 ir-rounded ir-bg-muted ir-px-2 ir-py-1 ir-font-mono ir-text-[11px] hover:ir-text-foreground"
                    >
                        <DownloadCloud className="ir-size-3" />
                        v{(version ?? '—').replace(/^v/, '')}
                    </button>
                </div>
            </aside>

            <main className="ir-min-w-0 ir-flex-1 ir-overflow-hidden">
                {fullBleed ? (
                    <Screen view={view} />
                ) : (
                    <div className="ir-h-full ir-overflow-y-auto">
                        <div className="ir-mx-auto ir-max-w-6xl ir-p-8">
                            <Screen view={view} />
                        </div>
                    </div>
                )}
            </main>
        </div>
    );
}
