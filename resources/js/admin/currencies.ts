// Supported reporting currencies — mirrors App\Models\Site::CURRENCIES.
// No FX conversion: each site's amounts render in its own currency (CLAUDE.md §5).
export const CURRENCIES: { code: string; label: string }[] = [
    { code: 'USD', label: 'Dólar estadounidense (USD)' },
    { code: 'COP', label: 'Peso colombiano (COP)' },
    { code: 'CLP', label: 'Peso chileno (CLP)' },
    { code: 'PEN', label: 'Sol peruano (PEN)' },
    { code: 'VES', label: 'Bolívar venezolano (VES)' },
    { code: 'ARS', label: 'Peso argentino (ARS)' },
    { code: 'MXN', label: 'Peso mexicano (MXN)' },
    { code: 'BRL', label: 'Real brasileño (BRL)' },
    { code: 'BOB', label: 'Boliviano (BOB)' },
    { code: 'UYU', label: 'Peso uruguayo (UYU)' },
    { code: 'PYG', label: 'Guaraní paraguayo (PYG)' },
    { code: 'GTQ', label: 'Quetzal guatemalteco (GTQ)' },
    { code: 'CRC', label: 'Colón costarricense (CRC)' },
    { code: 'DOP', label: 'Peso dominicano (DOP)' },
    { code: 'EUR', label: 'Euro (EUR)' },
];
