import '../../css/app.css';

import { QueryClientProvider } from '@tanstack/react-query';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { createQueryClient } from '@shared/lib/queryClient';

import { App } from './App';
import { ResetPasswordScreen } from './screens/ResetPasswordScreen';

const queryClient = createQueryClient();

// The password-reset link (#/reset-password?token=…) is reachable without a session, so it
// bypasses App's auth gate entirely — decided here at mount from the URL hash.
const isResetRoute = window.location.hash.startsWith('#/reset-password');

const container = document.getElementById('ir-admin-root');

if (container === null) {
    throw new Error('Admin SPA mount point #ir-admin-root not found.');
}

createRoot(container).render(
    <StrictMode>
        <QueryClientProvider client={queryClient}>
            {isResetRoute ? <ResetPasswordScreen /> : <App />}
        </QueryClientProvider>
    </StrictMode>,
);
