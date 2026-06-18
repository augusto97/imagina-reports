export {};

declare global {
    interface Window {
        /** Set by the report page once every block has rendered; Browsershot waits on it (§11.4). */
        reportReady?: boolean;
    }
}
