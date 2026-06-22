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
                accent: {
                    DEFAULT: 'hsl(var(--ir-accent))',
                    foreground: 'hsl(var(--ir-accent-foreground))',
                },
                success: 'hsl(var(--ir-success))',
                warning: 'hsl(var(--ir-warning))',
                danger: 'hsl(var(--ir-danger))',
                info: 'hsl(var(--ir-info))',
            },
            borderRadius: {
                lg: 'var(--ir-radius)',
                md: 'calc(var(--ir-radius) - 2px)',
                sm: 'calc(var(--ir-radius) - 4px)',
            },
            boxShadow: {
                'ir-xs': '0 1px 2px 0 rgb(16 24 40 / 0.04)',
                'ir-sm': '0 1px 3px 0 rgb(16 24 40 / 0.06), 0 1px 2px -1px rgb(16 24 40 / 0.04)',
                'ir-md': '0 4px 12px -2px rgb(16 24 40 / 0.08), 0 2px 6px -2px rgb(16 24 40 / 0.05)',
                'ir-lg': '0 12px 28px -6px rgb(16 24 40 / 0.12), 0 4px 10px -4px rgb(16 24 40 / 0.06)',
            },
        },
    },
    plugins: [animate],
};
