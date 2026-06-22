import { ChevronDown } from 'lucide-react';
import { type ReactElement, type ReactNode, useState } from 'react';

import { cn } from '@shared/lib/utils';

/**
 * Reusable editor UI primitives (CLAUDE.md §11.3) — the premium control language shared
 * across the report editor: collapsible sections, segmented controls, toolbar buttons and
 * chips. Looker-clean base with Power-BI-grade richness. Tailwind prefix `ir-`.
 */

/** A collapsible, titled section — replaces stacked Cards so panels read as one surface. */
export function Section({
    title,
    icon,
    action,
    defaultOpen = true,
    children,
}: {
    title: string;
    icon?: ReactNode;
    action?: ReactNode;
    defaultOpen?: boolean;
    children: ReactNode;
}): ReactElement {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <section className="ir-border-b ir-border-border/60 last:ir-border-b-0">
            <div className="ir-flex ir-items-center ir-gap-2 ir-px-3 ir-py-2.5">
                <button
                    type="button"
                    onClick={() => setOpen((value) => !value)}
                    className="ir-flex ir-flex-1 ir-items-center ir-gap-2 ir-text-left"
                >
                    {icon !== undefined && <span className="ir-text-muted-foreground">{icon}</span>}
                    <span className="ir-flex-1 ir-text-[11px] ir-font-semibold ir-uppercase ir-tracking-wider ir-text-muted-foreground">
                        {title}
                    </span>
                    <ChevronDown className={cn('ir-size-4 ir-shrink-0 ir-text-muted-foreground ir-transition-transform', open ? '' : '-ir-rotate-90')} />
                </button>
                {action}
            </div>
            {open && <div className="ir-px-3 ir-pb-3">{children}</div>}
        </section>
    );
}

export interface SegmentOption<T extends string> {
    value: T;
    label?: string;
    icon?: ReactNode;
    title?: string;
}

/** A segmented control (iOS/Looker style) — premium replacement for small selects/toggles. */
export function SegmentedControl<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (value: T) => void;
    options: SegmentOption<T>[];
}): ReactElement {
    return (
        <div className="ir-inline-flex ir-w-full ir-rounded-lg ir-bg-muted ir-p-0.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    title={option.title ?? option.label}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'ir-flex ir-flex-1 ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-md ir-px-2 ir-py-1.5 ir-text-xs ir-font-medium ir-transition',
                        value === option.value
                            ? 'ir-bg-card ir-text-foreground ir-shadow-sm'
                            : 'ir-text-muted-foreground hover:ir-text-foreground',
                    )}
                >
                    {option.icon}
                    {option.label}
                </button>
            ))}
        </div>
    );
}

/** A compact square icon button for toolbars (undo, panels, …) with disabled + active states. */
export function ToolbarButton({
    icon,
    title,
    onClick,
    disabled = false,
    active = false,
}: {
    icon: ReactNode;
    title: string;
    onClick: () => void;
    disabled?: boolean;
    active?: boolean;
}): ReactElement {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            title={title}
            aria-label={title}
            className={cn(
                'ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-md ir-text-muted-foreground ir-transition',
                'hover:ir-bg-muted hover:ir-text-foreground disabled:ir-pointer-events-none disabled:ir-opacity-40',
                active && 'ir-bg-muted ir-text-foreground',
            )}
        >
            {icon}
        </button>
    );
}

/** A thin vertical divider for grouping toolbar controls. */
export function ToolbarDivider(): ReactElement {
    return <span className="ir-mx-1 ir-h-5 ir-w-px ir-bg-border" />;
}

/** A pill switch toggle (premium replacement for bare checkboxes). */
export function Toggle({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    label: ReactNode;
}): ReactElement {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className="ir-flex ir-w-full ir-items-center ir-justify-between ir-gap-3 ir-text-sm"
        >
            <span className="ir-text-left ir-text-foreground">{label}</span>
            <span className={cn('ir-relative ir-h-5 ir-w-9 ir-shrink-0 ir-rounded-full ir-transition', checked ? 'ir-bg-primary' : 'ir-bg-muted-foreground/30')}>
                <span className={cn('ir-absolute ir-top-0.5 ir-size-4 ir-rounded-full ir-bg-white ir-shadow ir-transition-all', checked ? 'ir-left-[1.125rem]' : 'ir-left-0.5')} />
            </span>
        </button>
    );
}

const DEFAULT_SWATCHES = ['#ffffff', '#f1f5f9', '#0f172a', '#6366f1', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444'];

/** A colour swatch row (presets + custom picker + clear) — Looker/Power-BI style. */
export function ColorSwatch({
    value,
    onChange,
    presets = DEFAULT_SWATCHES,
}: {
    value: string;
    onChange: (value: string | undefined) => void;
    presets?: string[];
}): ReactElement {
    return (
        <div className="ir-flex ir-flex-wrap ir-items-center ir-gap-1.5">
            {presets.map((color) => (
                <button
                    key={color}
                    type="button"
                    title={color}
                    onClick={() => onChange(color)}
                    className={cn(
                        'ir-size-6 ir-rounded-md ir-border ir-transition',
                        value.toLowerCase() === color.toLowerCase() ? 'ir-ring-2 ir-ring-primary ir-ring-offset-1' : 'hover:ir-scale-110',
                    )}
                    style={{ backgroundColor: color }}
                />
            ))}
            <label className="ir-relative ir-size-6 ir-cursor-pointer ir-overflow-hidden ir-rounded-md ir-border" title="Color personalizado">
                <span
                    className="ir-block ir-size-full"
                    style={{ background: value !== '' ? value : 'conic-gradient(from 90deg, #ef4444, #f59e0b, #10b981, #0ea5e9, #6366f1, #ef4444)' }}
                />
                <input
                    type="color"
                    value={value !== '' ? value : '#ffffff'}
                    onChange={(event) => onChange(event.target.value)}
                    className="ir-absolute ir-inset-0 ir-size-full ir-cursor-pointer ir-opacity-0"
                />
            </label>
            {value !== '' && (
                <button type="button" onClick={() => onChange(undefined)} className="ir-text-[11px] ir-text-muted-foreground hover:ir-text-foreground">
                    quitar
                </button>
            )}
        </div>
    );
}
