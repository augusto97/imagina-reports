import { Database, FileBarChart, Globe, LayoutDashboard, Users } from 'lucide-react';
import { type ReactElement } from 'react';

import { cn } from '@shared/lib/utils';

import { ClientsScreen } from './screens/ClientsScreen';
import { DataSourcesScreen } from './screens/DataSourcesScreen';
import { ReportsScreen } from './screens/ReportsScreen';
import { SitesScreen } from './screens/SitesScreen';
import { type AdminView, useAdminUi } from './store';

const NAV: { view: AdminView; label: string; icon: typeof Users }[] = [
    { view: 'clients', label: 'Clientes', icon: Users },
    { view: 'sites', label: 'Sitios', icon: Globe },
    { view: 'data-sources', label: 'Fuentes', icon: Database },
    { view: 'reports', label: 'Reportes', icon: FileBarChart },
];

function Screen({ view }: { view: AdminView }): ReactElement {
    switch (view) {
        case 'clients':
            return <ClientsScreen />;
        case 'sites':
            return <SitesScreen />;
        case 'data-sources':
            return <DataSourcesScreen />;
        case 'reports':
            return <ReportsScreen />;
    }
}

export function App(): ReactElement {
    const view = useAdminUi((state) => state.view);
    const setView = useAdminUi((state) => state.setView);

    return (
        <div className="ir-min-h-screen ir-bg-background ir-text-foreground">
            <div className="ir-mx-auto ir-flex ir-max-w-6xl ir-gap-8 ir-p-8">
                <aside className="ir-w-48 ir-shrink-0">
                    <div className="ir-mb-6 ir-flex ir-items-center ir-gap-2">
                        <LayoutDashboard className="ir-size-5 ir-text-primary" />
                        <span className="ir-font-semibold">Imagina Reports</span>
                    </div>
                    <nav className="ir-flex ir-flex-col ir-gap-1">
                        {NAV.map((item) => (
                            <button
                                key={item.view}
                                type="button"
                                onClick={() => setView(item.view)}
                                className={cn(
                                    'ir-flex ir-items-center ir-gap-2 ir-rounded-md ir-px-3 ir-py-2 ir-text-left ir-text-sm',
                                    view === item.view ? 'ir-bg-muted ir-font-medium' : 'ir-text-muted-foreground hover:ir-bg-muted',
                                )}
                            >
                                <item.icon className="ir-size-4" />
                                {item.label}
                            </button>
                        ))}
                    </nav>
                </aside>

                <main className="ir-flex-1">
                    <Screen view={view} />
                </main>
            </div>
        </div>
    );
}
