import { create } from 'zustand';

export type AdminView = 'clients' | 'sites' | 'data-sources' | 'worklogs' | 'reports' | 'editor' | 'templates' | 'trends' | 'system' | 'settings';

/** UI/navigation state for the admin SPA (CLAUDE.md §11.1 — Zustand for UI state). */
interface AdminUiState {
    view: AdminView;
    selectedSiteId: number | null;
    editingTemplateId: number | null;
    setView: (view: AdminView) => void;
    selectSite: (siteId: number) => void;
    editTemplate: (templateId: number | null) => void;
}

export const useAdminUi = create<AdminUiState>((set) => ({
    view: 'clients',
    selectedSiteId: null,
    editingTemplateId: null,
    setView: (view) => set({ view }),
    selectSite: (siteId) => set({ selectedSiteId: siteId, view: 'data-sources' }),
    // Open a template in the editor (null = start a new one).
    editTemplate: (templateId) => set({ editingTemplateId: templateId, view: 'editor' }),
}));
