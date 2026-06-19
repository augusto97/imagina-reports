import '../../css/app.css';

import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { createQueryClient } from '@shared/lib/queryClient';

import { ReportApp } from './ReportApp';

const queryClient = createQueryClient();

const container = document.getElementById('ir-report-root');

if (container === null) {
    throw new Error('Report mount point #ir-report-root not found.');
}

const token = container.dataset.token ?? '';

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <ReportApp token={token} />
        </QueryClientProvider>
    </StrictMode>,
);
