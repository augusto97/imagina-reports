import { create } from 'zustand';

export type AdminView = 'clients' | 'sites' | 'data-sources' | 'reports' | 'editor' | 'trends' | 'system';

/** UI/navigation state for the admin SPA (CLAUDE.md §11.1 — Zustand for UI state). */
interface AdminUiState {
    view: AdminView;
    selectedSiteId: number | null;
    setView: (view: AdminView) => void;
    selectSite: (siteId: number) => void;
}

export const useAdminUi = create<AdminUiState>((set) => ({
    view: 'clients',
    selectedSiteId: null,
    setView: (view) => set({ view }),
    selectSite: (siteId) => set({ selectedSiteId: siteId, view: 'data-sources' }),
}));
