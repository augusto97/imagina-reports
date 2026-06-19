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
