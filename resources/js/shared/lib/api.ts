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
