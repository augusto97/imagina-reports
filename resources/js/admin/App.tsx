import { motion } from 'framer-motion';
import { Activity, LayoutDashboard } from 'lucide-react';

import { useHealth } from '@shared/hooks/useHealth';
import { cn } from '@shared/lib/utils';

import { useAdminUi } from './store';

/**
 * Phase 1 baseline shell for the admin SPA. Proves the locked stack is wired
 * end-to-end (TanStack Query → API, Zustand, Framer Motion, Lucide, Tailwind `ir-`).
 * Real screens (clients/sites, data sources, editor) are built in later tasks.
 */
export function App() {
    const { data, isLoading, isError } = useHealth();
    const sidebarOpen = useAdminUi((state) => state.sidebarOpen);
    const toggleSidebar = useAdminUi((state) => state.toggleSidebar);

    return (
        <div className="ir-min-h-screen ir-bg-background ir-text-foreground">
            <div className="ir-mx-auto ir-flex ir-max-w-5xl ir-flex-col ir-gap-6 ir-p-8">
                <header className="ir-flex ir-items-center ir-gap-3">
                    <LayoutDashboard className="ir-size-6 ir-text-primary" />
                    <h1 className="ir-text-xl ir-font-semibold">Imagina Reports — Admin</h1>
                </header>

                <motion.section
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="ir-rounded-lg ir-border ir-bg-card ir-p-6"
                >
                    <div className="ir-flex ir-items-center ir-gap-2 ir-text-muted-foreground">
                        <Activity className="ir-size-4" />
                        <span className="ir-text-sm">API health</span>
                    </div>
                    <p
                        className={cn(
                            'ir-mt-2 ir-text-2xl ir-font-semibold',
                            isError && 'ir-text-red-500',
                        )}
                    >
                        {isLoading ? 'Checking…' : isError ? 'Unreachable' : (data?.status ?? 'unknown')}
                    </p>
                </motion.section>

                <button
                    type="button"
                    onClick={toggleSidebar}
                    className="ir-w-fit ir-rounded-md ir-bg-primary ir-px-4 ir-py-2 ir-text-sm ir-font-medium ir-text-primary-foreground"
                >
                    Sidebar: {sidebarOpen ? 'open' : 'closed'}
                </button>
            </div>
        </div>
    );
}
