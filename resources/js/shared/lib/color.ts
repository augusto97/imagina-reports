/**
 * Convert a `#rrggbb` hex colour to the `"H S% L%"` triple our Tailwind tokens use
 * (so an agency's brand_color can override --ir-primary at runtime — white-label).
 */
export function hexToHslString(hex: string): string | null {
    const match = /^#?([0-9a-f]{6})$/i.exec(hex.trim());
    const group = match?.[1];

    if (group === undefined) {
        return null;
    }

    const int = parseInt(group, 16);
    const r = ((int >> 16) & 255) / 255;
    const g = ((int >> 8) & 255) / 255;
    const b = (int & 255) / 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;
    const l = (max + min) / 2;

    let h = 0;
    let s = 0;

    if (delta !== 0) {
        s = l > 0.5 ? delta / (2 - max - min) : delta / (max + min);
        if (max === r) {
            h = (g - b) / delta + (g < b ? 6 : 0);
        } else if (max === g) {
            h = (b - r) / delta + 2;
        } else {
            h = (r - g) / delta + 4;
        }
        h /= 6;
    }

    return `${Math.round(h * 360)} ${Math.round(s * 100)}% ${Math.round(l * 100)}%`;
}
