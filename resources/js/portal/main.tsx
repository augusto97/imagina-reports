import '../../css/app.css';

import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { createQueryClient } from '@shared/lib/queryClient';

import { App } from './App';

const queryClient = createQueryClient();

const container = document.getElementById('ir-portal-root');

if (container === null) {
    throw new Error('Portal SPA mount point #ir-portal-root not found.');
}

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            <App />
        </QueryClientProvider>
    </StrictMode>,
);
