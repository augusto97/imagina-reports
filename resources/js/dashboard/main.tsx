import '../../css/app.css';

import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { createQueryClient } from '@shared/lib/queryClient';

import { DashboardApp } from './DashboardApp';

const queryClient = createQueryClient();

const container = document.getElementById('ir-dashboard-root');

if (container === null) {
    throw new Error('Dashboard SPA mount point #ir-dashboard-root not found.');
}

const token = container.dataset.token ?? '';

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <DashboardApp token={token} />
        </QueryClientProvider>
    </StrictMode>,
);
