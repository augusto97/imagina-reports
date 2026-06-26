export interface GeoRow {
    label: string;
    value: number;
}

// Names in the bundled world atlas that differ from common GA4 `country` values.
// Map the GA4/everyday name → the atlas name, both already normalised by `norm()`.
const ALIASES: Record<string, string> = {
    'united states': 'united states of america',
    usa: 'united states of america',
    us: 'united states of america',
    uk: 'united kingdom',
    'great britain': 'united kingdom',
    'south korea': 'south korea',
    'republic of korea': 'south korea',
    'north korea': 'north korea',
    'russia': 'russia',
    'russian federation': 'russia',
    'czechia': 'czechia',
    'czech republic': 'czechia',
    'ivory coast': "cote d'ivoire",
    'democratic republic of the congo': 'dem. rep. congo',
    'republic of the congo': 'congo',
    'bosnia and herzegovina': 'bosnia and herz.',
    'dominican republic': 'dominican rep.',
    'south sudan': 's. sudan',
    'tanzania': 'tanzania',
    'united republic of tanzania': 'tanzania',
    'myanmar': 'myanmar',
    'burma': 'myanmar',
    'laos': 'laos',
    'syria': 'syria',
    'venezuela': 'venezuela',
    'bolivia': 'bolivia',
    'vietnam': 'vietnam',
    'viet nam': 'vietnam',
    'eswatini': 'eswatini',
    'swaziland': 'eswatini',
    'macedonia': 'macedonia',
    'north macedonia': 'macedonia',
    'equatorial guinea': 'eq. guinea',
    'central african republic': 'central african rep.',
    'solomon islands': 'solomon is.',
    'western sahara': 'w. sahara',

    // Spanish names (GA4 usually returns English, but data/labels may be localised).
    'espana': 'spain',
    'estados unidos': 'united states of america',
    'estados unidos de america': 'united states of america',
    'reino unido': 'united kingdom',
    'alemania': 'germany',
    'francia': 'france',
    'italia': 'italy',
    'brasil': 'brazil',
    'japon': 'japan',
    'paises bajos': 'netherlands',
    'belgica': 'belgium',
    'suiza': 'switzerland',
    'grecia': 'greece',
    'turquia': 'turkey',
    'marruecos': 'morocco',
    'egipto': 'egypt',
    'sudafrica': 'south africa',
    'corea del sur': 'south korea',
    'arabia saudita': 'saudi arabia',
    'emiratos arabes unidos': 'united arab emirates',
};

/** Normalise a place name for matching: lowercase, strip accents/punctuation, collapse spaces. */
function norm(name: string): string {
    return name
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z\s]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

// The set of normalised atlas country names is built lazily from the geographies the
// choropleth renders; here we only need a canonical key. We canonicalise to the atlas
// name when we know an alias, otherwise to the normalised input — the choropleth applies
// the same function to both the data rows and the atlas features, so equal names match.
/**
 * Canonical country key for matching a data label against the world atlas. Returns null
 * for values that clearly aren't countries (empty), so the block can detect city/region
 * data and fall back to the ranked list.
 */
export function matchCountry(name: string): string | null {
    const normalised = norm(name);
    if (normalised === '') {
        return null;
    }

    // Alias targets are normalised too, so an atlas feature ("Dem. Rep. Congo" → "dem rep
    // congo") and the everyday name resolve to the exact same key.
    const alias = ALIASES[normalised];

    return alias !== undefined ? norm(alias) : normalised;
}
