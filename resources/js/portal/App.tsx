import { motion } from 'framer-motion';
import { ShieldCheck } from 'lucide-react';

import { useHealth } from '@shared/hooks/useHealth';

/**
 * Phase 1 baseline shell for the interactive client portal SPA (CLAUDE.md §11.2).
 * Opened later via a signed public_token; the interactive dashboard and the
 * shared BlockRenderer arrive in subsequent Phase 1 tasks.
 */
export function App() {
    const { data, isLoading } = useHealth();

    return (
        <div className="ir-min-h-screen ir-bg-background ir-text-foreground">
            <div className="ir-mx-auto ir-flex ir-max-w-3xl ir-flex-col ir-gap-6 ir-p-8">
                <motion.header
                    initial={{ opacity: 0, y: 8 }}
                    animate={{ opacity: 1, y: 0 }}
                    className="ir-flex ir-items-center ir-gap-3"
                >
                    <ShieldCheck className="ir-size-6 ir-text-primary" />
                    <h1 className="ir-text-xl ir-font-semibold">Imagina Reports — Portal</h1>
                </motion.header>

                <p className="ir-text-sm ir-text-muted-foreground">
                    {isLoading ? 'Loading…' : `Service status: ${data?.status ?? 'unknown'}`}
                </p>
            </div>
        </div>
    );
}
