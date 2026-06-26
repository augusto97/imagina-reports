import { geoNaturalEarth1, geoPath } from 'd3-geo';
import { type ReactElement, useMemo } from 'react';
import { feature } from 'topojson-client';
import worldTopo from 'world-atlas/countries-110m.json';

import type { GeoRow } from './geo';
import { matchCountry } from './geo';

// Decode the bundled world atlas once (offline → PDF-safe, no runtime fetch).
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const COUNTRIES = feature(worldTopo as any, (worldTopo as any).objects.countries) as unknown as {
    features: Array<{ properties: { name: string } }>;
};

const WIDTH = 800;
const HEIGHT = 380;

/**
 * A literal world choropleth (CLAUDE.md §11/Etapa C upgrade): countries shaded by value
 * in the agency accent colour. Pure inline SVG with a bundled atlas, so it renders the
 * same in the portal and the Chromium-printed PDF (no canvas, no network). Returns the
 * SVG plus how many input rows matched a country, so the block can fall back to the list
 * when the data isn't country-level (cities/regions).
 */
export function WorldChoropleth({ rows }: { rows: GeoRow[] }): ReactElement {
    const { paths, matched } = useMemo(() => {
        // Map normalised country name → value from the input rows.
        const values = new Map<string, number>();
        let matchedCount = 0;
        for (const row of rows) {
            const key = matchCountry(row.label);
            if (key !== null) {
                values.set(key, (values.get(key) ?? 0) + row.value);
                matchedCount += 1;
            }
        }

        const max = Math.max(0, ...[...values.values()]);
        const projection = geoNaturalEarth1().fitSize([WIDTH, HEIGHT], COUNTRIES as never);
        const path = geoPath(projection);

        const shapes = COUNTRIES.features.map((country, index) => {
            const key = matchCountry(country.properties.name);
            const value = key !== null ? (values.get(key) ?? 0) : 0;
            // Intensity by value; countries without data stay faint.
            const opacity = max > 0 && value > 0 ? 0.2 + 0.8 * (value / max) : 0;
            const d = path(country as never) ?? '';

            return { d, opacity, key: index };
        });

        return { paths: shapes, matched: matchedCount };
    }, [rows]);

    return (
        <div className="ir-text-accent" data-matched={matched}>
            <svg viewBox={`0 0 ${WIDTH} ${HEIGHT}`} className="ir-h-auto ir-w-full" role="img" aria-label="Mapa por país">
                {paths.map((shape) => (
                    <path
                        key={shape.key}
                        d={shape.d}
                        // Faint base fill for every country; data countries get accent on top.
                        className="ir-fill-muted"
                        stroke="white"
                        strokeWidth={0.4}
                    />
                ))}
                {paths
                    .filter((shape) => shape.opacity > 0)
                    .map((shape) => (
                        <path key={`v-${shape.key}`} d={shape.d} fill="currentColor" fillOpacity={shape.opacity} stroke="white" strokeWidth={0.4} />
                    ))}
            </svg>
        </div>
    );
}
