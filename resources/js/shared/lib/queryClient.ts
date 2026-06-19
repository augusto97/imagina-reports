import { QueryClient } from '@tanstack/react-query';

/** One shared TanStack Query client configuration for both SPAs. */
export function createQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: {
                staleTime: 30_000,
                retry: 1,
                refetchOnWindowFocus: false,
            },
        },
    });
}
