import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@shared/lib/api';

/** One message in the report's conversation, as the portal sees it (never anyone's email). */
export interface PortalMessage {
    id: number;
    kind: 'client' | 'agency' | 'ai';
    author: string | null;
    body: string;
    answer: string | null;
    created_at: string;
}

export interface PortalConversation {
    messages: PortalMessage[];
    ai_available: boolean;
    agency: string | null;
}

function headers(password?: string): Record<string, string> {
    return password != null && password !== '' ? { 'X-Report-Password': password } : {};
}

export function useConversation(token: string, password: string | undefined, live: boolean) {
    return useQuery({
        queryKey: ['portal-conversation', token, password ?? ''],
        queryFn: async () => (await api.get<PortalConversation>(`/public/reports/${token}/messages`, { headers: headers(password) })).data,
        retry: false,
        // While the panel is open, an answer from the agency shows up without reloading.
        refetchInterval: live ? 45_000 : false,
    });
}

export function useAskReport(token: string, password: string | undefined) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: { question: string; name?: string }) =>
            (await api.post<{ message: PortalMessage }>(`/public/reports/${token}/ask`, payload, { headers: headers(password) })).data.message,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal-conversation', token] }),
    });
}

export function useSendQuestion(token: string, password: string | undefined) {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (payload: { name: string; email: string; body: string; website: string }) =>
            (await api.post<{ message: PortalMessage | null }>(`/public/reports/${token}/messages`, payload, { headers: headers(password) })).data.message,
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['portal-conversation', token] }),
    });
}

const IDENTITY_KEY = 'ir-portal-identity';

/** Who's asking, remembered on this device so a second question doesn't ask again. */
export function rememberedIdentity(): { name: string; email: string } {
    try {
        const raw = window.localStorage.getItem(IDENTITY_KEY);
        const parsed = raw !== null ? (JSON.parse(raw) as { name?: unknown; email?: unknown }) : {};

        return { name: typeof parsed.name === 'string' ? parsed.name : '', email: typeof parsed.email === 'string' ? parsed.email : '' };
    } catch {
        return { name: '', email: '' };
    }
}

export function rememberIdentity(identity: { name: string; email: string }): void {
    try {
        window.localStorage.setItem(IDENTITY_KEY, JSON.stringify(identity));
    } catch {
        // Private mode / storage blocked: they'll just type it again next time.
    }
}
