// Common reporting timezones (LATAM-first), used to render client-facing timestamps
// in the client's own zone (CLAUDE.md §5). Values are IANA identifiers validated
// server-side with Laravel's `timezone` rule.
export const TIMEZONES: { value: string; label: string }[] = [
    { value: 'America/Bogota', label: 'Colombia / Perú / Ecuador (GMT-5)' },
    { value: 'America/Mexico_City', label: 'México central (GMT-6)' },
    { value: 'America/Santiago', label: 'Chile (GMT-4/-3)' },
    { value: 'America/Argentina/Buenos_Aires', label: 'Argentina (GMT-3)' },
    { value: 'America/Sao_Paulo', label: 'Brasil — São Paulo (GMT-3)' },
    { value: 'America/Caracas', label: 'Venezuela (GMT-4)' },
    { value: 'America/La_Paz', label: 'Bolivia (GMT-4)' },
    { value: 'America/Asuncion', label: 'Paraguay (GMT-4/-3)' },
    { value: 'America/Montevideo', label: 'Uruguay (GMT-3)' },
    { value: 'America/Guayaquil', label: 'Ecuador (GMT-5)' },
    { value: 'America/Lima', label: 'Perú (GMT-5)' },
    { value: 'America/Guatemala', label: 'Guatemala / Centroamérica (GMT-6)' },
    { value: 'America/Costa_Rica', label: 'Costa Rica (GMT-6)' },
    { value: 'America/Santo_Domingo', label: 'Rep. Dominicana (GMT-4)' },
    { value: 'America/New_York', label: 'EE. UU. — Este (GMT-5/-4)' },
    { value: 'America/Los_Angeles', label: 'EE. UU. — Pacífico (GMT-8/-7)' },
    { value: 'Europe/Madrid', label: 'España (GMT+1/+2)' },
    { value: 'UTC', label: 'UTC (GMT+0)' },
];
