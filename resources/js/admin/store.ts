import { create } from 'zustand';

/** UI-only state for the admin SPA (CLAUDE.md §11.1 — Zustand for UI state). */
interface AdminUiState {
    sidebarOpen: boolean;
    toggleSidebar: () => void;
}

export const useAdminUi = create<AdminUiState>((set) => ({
    sidebarOpen: true,
    toggleSidebar: () => set((state) => ({ sidebarOpen: !state.sidebarOpen })),
}));
