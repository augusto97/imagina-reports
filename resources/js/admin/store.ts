import { create } from 'zustand';

export type AdminView = 'clients' | 'sites' | 'data-sources' | 'worklogs' | 'reports' | 'editor' | 'templates' | 'trends' | 'upsell' | 'alerts' | 'team' | 'system' | 'settings';

const VIEWS: AdminView[] = ['clients', 'sites', 'data-sources', 'worklogs', 'reports', 'editor', 'templates', 'trends', 'upsell', 'alerts', 'team', 'system', 'settings'];

/** Parse the active section from the URL hash (e.g. `#/reports` → `reports`). */
export function viewFromHash(): AdminView | null {
    const raw = window.location.hash.replace(/^#\/?/, '').split('?')[0] as AdminView;

    return VIEWS.includes(raw) ? raw : null;
}

export function viewHash(view: AdminView): string {
    return `#/${view}`;
}

/* ----------------------- Platform (super-admin) routes ---------------------- */

export type PlatformTab = 'overview' | 'agencies' | 'plans' | 'billing' | 'integrations' | 'system';

const PLATFORM_TABS: PlatformTab[] = ['overview', 'agencies', 'plans', 'billing', 'integrations', 'system'];

/**
 * The platform panel lives under its own `#/platform/…` namespace.
 *
 * It has one deliberately: its sections used to have no URL at all, so they were neither
 * linkable nor reloadable — and, worse, leaving an impersonated agency left that agency's
 * hash (`#/reports`) in the address bar while the super-admin panel was on screen.
 */
export function platformTabFromHash(): PlatformTab | null {
    const raw = window.location.hash.replace(/^#\/?/, '').split('?')[0] ?? '';
    if (!raw.startsWith('platform/')) {
        return null;
    }
    const tab = raw.slice('platform/'.length) as PlatformTab;

    return PLATFORM_TABS.includes(tab) ? tab : null;
}

export function platformHash(tab: PlatformTab): string {
    return `#/platform/${tab}`;
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
    /** Set when the API returns 402 anywhere → the agency is suspended (FE-2). Drives a
     *  global banner so screens stop showing misleading "all clear" empty states. */
    suspended: boolean;
    setView: (view: AdminView) => void;
    selectSite: (siteId: number) => void;
    editTemplate: (templateId: number | null) => void;
    setSuspended: (suspended: boolean) => void;
}

// The active section is restored from the URL hash and the auxiliary ids from
// localStorage, so a page reload keeps you where you were instead of resetting to Clients.
export const useAdminUi = create<AdminUiState>((set) => ({
    view: viewFromHash() ?? 'clients',
    selectedSiteId: persistedId('ir-selected-site'),
    editingTemplateId: persistedId('ir-editing-template'),
    suspended: false,
    setView: (view) => set({ view }),
    setSuspended: (suspended) => set({ suspended }),
    // Selecting a site focuses it in the unified Clientes workspace (master-detail).
    selectSite: (siteId) => {
        window.localStorage.setItem('ir-selected-site', String(siteId));
        set({ selectedSiteId: siteId, view: 'clients' });
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
