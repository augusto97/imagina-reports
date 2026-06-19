import defaultTheme from 'tailwindcss/defaultTheme';
import animate from 'tailwindcss-animate';

/**
 * Tailwind is namespaced with the `ir-` prefix (CLAUDE.md §6) so the SPA styles
 * never collide with anything embedded in a white-labelled report or portal.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    darkMode: ['class'],
    prefix: 'ir-',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{ts,tsx}',
    ],
    theme: {
        container: {
            center: true,
            padding: '2rem',
            screens: { '2xl': '1400px' },
        },
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                border: 'hsl(var(--ir-border))',
                input: 'hsl(var(--ir-input))',
                ring: 'hsl(var(--ir-ring))',
                background: 'hsl(var(--ir-background))',
                foreground: 'hsl(var(--ir-foreground))',
                primary: {
                    DEFAULT: 'hsl(var(--ir-primary))',
                    foreground: 'hsl(var(--ir-primary-foreground))',
                },
                muted: {
                    DEFAULT: 'hsl(var(--ir-muted))',
                    foreground: 'hsl(var(--ir-muted-foreground))',
                },
                card: {
                    DEFAULT: 'hsl(var(--ir-card))',
                    foreground: 'hsl(var(--ir-card-foreground))',
                },
            },
            borderRadius: {
                lg: 'var(--ir-radius)',
                md: 'calc(var(--ir-radius) - 2px)',
                sm: 'calc(var(--ir-radius) - 4px)',
            },
        },
    },
    plugins: [animate],
};
