import { create } from 'zustand';

export type AdminView = 'clients' | 'sites' | 'data-sources' | 'worklogs' | 'reports' | 'editor' | 'templates' | 'trends' | 'upsell' | 'system' | 'settings';

const VIEWS: AdminView[] = ['clients', 'sites', 'data-sources', 'worklogs', 'reports', 'editor', 'templates', 'trends', 'upsell', 'system', 'settings'];

/** Parse the active section from the URL hash (e.g. `#/reports` → `reports`). */
export function viewFromHash(): AdminView | null {
    const raw = window.location.hash.replace(/^#\/?/, '').split('?')[0] as AdminView;

    return VIEWS.includes(raw) ? raw : null;
}

function persistedId(key: string): number | null {
    const raw = window.localStorage.getItem(key);

    return raw !== null && raw !== '' ? Number(raw) : null;
}

/** UI/navigation state for the admin SPA (CLAUDE.md §11.1 — Zustand for UI state). */
interface AdminUiState {
    view: AdminView;
    selectedSiteId: number | null;
    editingTemplateId: number | null;
    setView: (view: AdminView) => void;
    selectSite: (siteId: number) => void;
    editTemplate: (templateId: number | null) => void;
}

// The active section is restored from the URL hash and the auxiliary ids from
// localStorage, so a page reload keeps you where you were instead of resetting to Clients.
export const useAdminUi = create<AdminUiState>((set) => ({
    view: viewFromHash() ?? 'clients',
    selectedSiteId: persistedId('ir-selected-site'),
    editingTemplateId: persistedId('ir-editing-template'),
    setView: (view) => set({ view }),
    selectSite: (siteId) => {
        window.localStorage.setItem('ir-selected-site', String(siteId));
        set({ selectedSiteId: siteId, view: 'data-sources' });
    },
    // Open a template in the editor (null = start a new one).
    editTemplate: (templateId) => {
        if (templateId === null) {
            window.localStorage.removeItem('ir-editing-template');
        } else {
            window.localStorage.setItem('ir-editing-template', String(templateId));
        }
        set({ editingTemplateId: templateId, view: 'editor' });
    },
}));
