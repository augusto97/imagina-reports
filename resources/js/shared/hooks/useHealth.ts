import { useQuery } from '@tanstack/react-query';

import { api } from '@shared/lib/api';

export interface HealthStatus {
    status: string;
    app: string;
    time: string;
}

/** Polls the API liveness probe — a tiny end-to-end check that the stack is wired. */
export function useHealth() {
    return useQuery<HealthStatus>({
        queryKey: ['health'],
        queryFn: async () => {
            const { data } = await api.get<HealthStatus>('/health');

            return data;
        },
    });
}
