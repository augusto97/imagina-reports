import axios from 'axios';

/**
 * Shared axios instance for the first-party SPAs. Talks to the versioned API
 * (CLAUDE.md §8) and carries Sanctum's cookie + XSRF token for stateful auth.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
    },
});

/**
 * Prime Sanctum's XSRF-TOKEN cookie before a stateful POST (login). Must hit the
 * root path, not the /api/v1 base.
 */
export async function fetchCsrfCookie(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

/**
 * App-level handlers for the two auth/billing status codes that must be reacted to
 * globally rather than per-request (FE-2): a session that expired mid-use (401) and a
 * suspended, non-paying agency (402 on almost every endpoint). Registered by each SPA's
 * root; without them the interceptor is a no-op and errors propagate as before.
 */
interface ApiErrorHandlers {
    onUnauthorized?: () => void;
    onPaymentRequired?: () => void;
}

let handlers: ApiErrorHandlers = {};

export function setApiErrorHandlers(next: ApiErrorHandlers): void {
    handlers = next;
}

/**
 * Best-effort human message from a failed request: the API's `message`, else the first
 * Laravel validation error, else a generic fallback. Lets forms surface *why* a save
 * failed (FE-3) instead of silently doing nothing.
 */
export function apiErrorMessage(error: unknown, fallback = 'Ocurrió un error. Inténtalo de nuevo.'): string {
    if (axios.isAxiosError(error)) {
        const data = error.response?.data as { message?: unknown; errors?: Record<string, unknown> } | undefined;

        if (typeof data?.message === 'string' && data.message !== '') {
            return data.message;
        }

        const first = data?.errors ? Object.values(data.errors)[0] : undefined;
        if (Array.isArray(first) && typeof first[0] === 'string') {
            return first[0];
        }
    }

    return fallback;
}

api.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
        if (axios.isAxiosError(error)) {
            const status = error.response?.status;
            const url = error.config?.url ?? '';
            // The `/user` auth probe and `/login` return 401 as their normal "not logged in"
            // signal — the login screen already handles those. Only a 401 elsewhere means a
            // live session expired, which is what we react to globally.
            const isAuthEndpoint = url === '/user' || url.startsWith('/login') || url.startsWith('/logout');

            if (status === 401 && !isAuthEndpoint) {
                handlers.onUnauthorized?.();
            } else if (status === 402) {
                handlers.onPaymentRequired?.();
            }
        }

        return Promise.reject(error);
    },
);
