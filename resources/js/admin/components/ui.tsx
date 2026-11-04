import { MoreHorizontal } from 'lucide-react';
import { forwardRef, type ReactElement, type ReactNode, useEffect, useRef, useState } from 'react';

import { cn } from '@shared/lib/utils';

type ButtonVariant = 'primary' | 'accent' | 'outline' | 'ghost' | 'quiet' | 'subtle' | 'danger';
type ButtonSize = 'sm' | 'md' | 'icon';

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
    primary: 'ir-bg-primary ir-text-primary-foreground ir-shadow-ir-xs hover:ir-opacity-90',
    accent: 'ir-bg-accent ir-text-accent-foreground ir-shadow-ir-xs hover:ir-opacity-90',
    outline: 'ir-border ir-bg-card ir-text-foreground ir-shadow-ir-xs hover:ir-bg-muted',
    // ghost keeps a subtle border (it's the admin's default secondary button).
    ghost: 'ir-border ir-bg-card ir-text-foreground ir-shadow-ir-xs hover:ir-bg-muted',
    quiet: 'ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground',
    subtle: 'ir-bg-muted ir-text-foreground hover:ir-bg-muted/70',
    danger: 'ir-bg-danger ir-text-white ir-shadow-ir-xs hover:ir-opacity-90',
};

const BUTTON_SIZES: Record<ButtonSize, string> = {
    sm: 'ir-h-8 ir-px-2.5 ir-text-xs',
    md: 'ir-h-9 ir-px-3.5 ir-text-sm',
    icon: 'ir-size-8 ir-p-0',
};

/** The admin's action button. Variants cover CTAs, secondary and destructive actions. */
export function Button({
    className,
    variant = 'primary',
    size = 'md',
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: ButtonVariant; size?: ButtonSize }): ReactElement {
    return (
        <button
            className={cn(
                'ir-inline-flex ir-shrink-0 ir-items-center ir-justify-center ir-gap-1.5 ir-whitespace-nowrap ir-rounded-lg ir-font-medium ir-transition-colors',
                'focus-visible:ir-outline-none focus-visible:ir-ring-2 focus-visible:ir-ring-ring focus-visible:ir-ring-offset-1 focus-visible:ir-ring-offset-background',
                'disabled:ir-pointer-events-none disabled:ir-opacity-50',
                BUTTON_SIZES[size],
                BUTTON_VARIANTS[variant],
                className,
            )}
            {...props}
        />
    );
}

const CONTROL_CLASS =
    'ir-h-9 ir-w-full ir-rounded-lg ir-border ir-bg-card ir-px-3 ir-text-sm ir-shadow-ir-xs ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40 disabled:ir-opacity-50';

/** Shared control styling so native <select>/<textarea> match the Input component. */
export const controlClass = CONTROL_CLASS;

// forwardRef so React Hook Form's `register` ref attaches to the real <input> —
// without it, autofilled values aren't captured (form thinks the field is empty).
export const Input = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    function Input({ className, ...props }, ref): ReactElement {
        return <input ref={ref} className={cn(CONTROL_CLASS, className)} {...props} />;
    },
);

/** A pre-styled <select> matching the Input, so dropdowns across the admin are consistent. */
export const Select = forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
    function Select({ className, children, ...props }, ref): ReactElement {
        return (
            <select ref={ref} className={cn(CONTROL_CLASS, 'ir-cursor-pointer', className)} {...props}>
                {children}
            </select>
        );
    },
);

export function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <label className="ir-flex ir-flex-col ir-gap-1.5">
            <span className="ir-text-xs ir-font-medium ir-text-foreground/80">{label}</span>
            {children}
            {hint !== undefined && error === undefined && <span className="ir-text-xs ir-text-muted-foreground">{hint}</span>}
            {error !== undefined && <span className="ir-text-xs ir-text-danger">{error}</span>}
        </label>
    );
}

export function Card({
    title,
    description,
    actions,
    className,
    children,
}: {
    title?: string;
    description?: string;
    actions?: ReactNode;
    className?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <section className={cn('ir-rounded-xl ir-border ir-bg-card ir-shadow-ir-xs', className)}>
            {(title !== undefined || actions !== undefined) && (
                <header className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3 ir-px-5 ir-pt-4">
                    <div className="ir-min-w-0">
                        {title !== undefined && <h2 className="ir-text-[15px] ir-font-semibold ir-tracking-tight">{title}</h2>}
                        {description !== undefined && <p className="ir-mt-0.5 ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">{description}</p>}
                    </div>
                    {actions !== undefined && <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-2">{actions}</div>}
                </header>
            )}
            <div className={title !== undefined || actions !== undefined ? 'ir-px-5 ir-pb-5 ir-pt-4' : 'ir-p-5'}>{children}</div>
        </section>
    );
}

/** Centered modal overlay. The child provides its own Card/surface. Esc / backdrop close. */
export function Modal({ onClose, children, className }: { onClose: () => void; children: ReactNode; className?: string }): ReactElement {
    useEffect(() => {
        const onKey = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-overflow-y-auto ir-bg-foreground/30 ir-p-4 ir-pt-[8vh] ir-backdrop-blur-[2px] ir-animate-in ir-fade-in-0">
            <button type="button" aria-label="Cerrar" onClick={onClose} className="ir-fixed ir-inset-0 ir-cursor-default" />
            <div className={cn('ir-relative ir-z-10 ir-w-full ir-max-w-lg ir-animate-in ir-fade-in-0 ir-zoom-in-95 ir-slide-in-from-top-2', className)}>{children}</div>
        </div>
    );
}

type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';

const BADGE_TONES: Record<Tone, string> = {
    neutral: 'ir-bg-muted ir-text-muted-foreground',
    accent: 'ir-bg-accent/10 ir-text-accent',
    success: 'ir-bg-success/10 ir-text-success',
    warning: 'ir-bg-warning/10 ir-text-warning',
    danger: 'ir-bg-danger/10 ir-text-danger',
    info: 'ir-bg-info/10 ir-text-info',
};

/** A small status pill (draft/approved/sent, OK/warning, etc.). */
export function Badge({ tone = 'neutral', className, children }: { tone?: Tone; className?: string; children: ReactNode }): ReactElement {
    return (
        <span
            className={cn(
                'ir-inline-flex ir-items-center ir-gap-1 ir-rounded-full ir-px-2 ir-py-0.5 ir-text-xs ir-font-medium',
                BADGE_TONES[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}

/* ------------------------------------------------------------------------------------------
 * Layout primitives shared by every screen, so each one reads the same way: a page header
 * (title, one-line purpose, the main action), tabs, stat tiles, menus and empty states.
 * ---------------------------------------------------------------------------------------- */

/** The top of every screen: what this is, why it matters, and its main action. */
export function PageHeader({
    title,
    description,
    actions,
    eyebrow,
    className,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    eyebrow?: ReactNode;
    /** Inside a container that already spaces its children, pass `ir-mb-0`. */
    className?: string;
}): ReactElement {
    return (
        <div className={cn('ir-mb-6 ir-flex ir-flex-col ir-gap-3 sm:ir-flex-row sm:ir-items-end sm:ir-justify-between', className)}>
            <div className="ir-min-w-0">
                {eyebrow !== undefined && <div className="ir-mb-1.5 ir-text-xs ir-font-medium ir-text-muted-foreground">{eyebrow}</div>}
                <h1 className="ir-text-[22px] ir-font-semibold ir-leading-tight ir-tracking-tight">{title}</h1>
                {description !== undefined && <p className="ir-mt-1 ir-max-w-2xl ir-text-sm ir-leading-relaxed ir-text-muted-foreground">{description}</p>}
            </div>
            {actions !== undefined && <div className="ir-flex ir-shrink-0 ir-flex-wrap ir-items-center ir-gap-2">{actions}</div>}
        </div>
    );
}

/** Underlined tabs (Linear style). Scrolls sideways on narrow screens instead of wrapping. */
export function Tabs<T extends string>({
    value,
    onChange,
    items,
    className,
}: {
    value: T;
    onChange: (value: T) => void;
    items: { value: T; label: string; count?: number; icon?: React.ComponentType<{ className?: string }> }[];
    className?: string;
}): ReactElement {
    return (
        <div className={cn('ir-flex ir-gap-1 ir-overflow-x-auto ir-border-b', className)} role="tablist">
            {items.map((item) => {
                const active = item.value === value;
                const Icon = item.icon;

                return (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(item.value)}
                        className={cn(
                            '-ir-mb-px ir-inline-flex ir-shrink-0 ir-items-center ir-gap-1.5 ir-border-b-2 ir-px-3 ir-py-2.5 ir-text-sm ir-transition-colors',
                            active ? 'ir-border-foreground ir-font-medium ir-text-foreground' : 'ir-border-transparent ir-text-muted-foreground hover:ir-text-foreground',
                        )}
                    >
                        {Icon !== undefined && <Icon className="ir-size-4" />}
                        {item.label}
                        {item.count !== undefined && (
                            <span className={cn('ir-rounded-full ir-px-1.5 ir-text-[11px] ir-font-medium ir-tabular-nums', active ? 'ir-bg-foreground ir-text-background' : 'ir-bg-muted ir-text-muted-foreground')}>
                                {item.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

/** Pill-shaped segmented filter (Todos / Borradores / …). */
export function Segmented<T extends string>({
    value,
    onChange,
    items,
    fill = false,
}: {
    value: T;
    onChange: (value: T) => void;
    items: { value: T; label: string; count?: number }[];
    fill?: boolean;
}): ReactElement {
    return (
        <div className={cn('ir-max-w-full ir-gap-0.5 ir-overflow-x-auto ir-rounded-lg ir-bg-muted ir-p-0.5', fill ? 'ir-flex [&>button]:ir-flex-1 [&>button]:ir-justify-center' : 'ir-inline-flex')}>
            {items.map((item) => (
                <button
                    key={item.value}
                    type="button"
                    onClick={() => onChange(item.value)}
                    className={cn(
                        'ir-inline-flex ir-shrink-0 ir-items-center ir-gap-1 ir-whitespace-nowrap ir-rounded-md ir-px-2 ir-py-1 ir-text-xs ir-font-medium ir-transition-colors',
                        item.value === value ? 'ir-bg-card ir-text-foreground ir-shadow-ir-xs' : 'ir-text-muted-foreground hover:ir-text-foreground',
                    )}
                >
                    {item.label}
                    {item.count !== undefined && <span className="ir-tabular-nums ir-text-muted-foreground">{item.count}</span>}
                </button>
            ))}
        </div>
    );
}

/** A headline number with a label and an optional hint/icon. */
export function StatCard({
    label,
    value,
    hint,
    icon: Icon,
    tone = 'neutral',
    onClick,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    icon?: React.ComponentType<{ className?: string }>;
    tone?: Tone;
    onClick?: () => void;
}): ReactElement {
    const body = (
        <>
            <div className="ir-flex ir-items-center ir-justify-between ir-gap-2">
                <span className="ir-text-[13px] ir-font-medium ir-text-muted-foreground">{label}</span>
                {Icon !== undefined && (
                    <span className={cn('ir-flex ir-size-7 ir-items-center ir-justify-center ir-rounded-lg', BADGE_TONES[tone])}>
                        <Icon className="ir-size-3.5" />
                    </span>
                )}
            </div>
            <div className="ir-mt-2 ir-text-2xl ir-font-semibold ir-tabular-nums ir-tracking-tight">{value}</div>
            {hint !== undefined && <div className="ir-mt-1 ir-text-xs ir-text-muted-foreground">{hint}</div>}
        </>
    );
    const classes = 'ir-rounded-xl ir-border ir-bg-card ir-p-4 ir-text-left ir-shadow-ir-xs';

    return onClick !== undefined ? (
        <button type="button" onClick={onClick} className={cn(classes, 'ir-transition hover:ir-border-foreground/20 hover:ir-shadow-ir-sm')}>
            {body}
        </button>
    ) : (
        <div className={classes}>{body}</div>
    );
}

/** What a list shows when it's empty: an icon, what's missing and the next step. */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}): ReactElement {
    return (
        <div className={cn('ir-flex ir-flex-col ir-items-center ir-rounded-xl ir-border ir-border-dashed ir-bg-card/60 ir-px-6 ir-py-12 ir-text-center', className)}>
            <span className="ir-flex ir-size-11 ir-items-center ir-justify-center ir-rounded-xl ir-bg-muted ir-text-muted-foreground">
                <Icon className="ir-size-5" />
            </span>
            <p className="ir-mt-3 ir-text-sm ir-font-semibold">{title}</p>
            {description !== undefined && <p className="ir-mt-1 ir-max-w-sm ir-text-[13px] ir-leading-relaxed ir-text-muted-foreground">{description}</p>}
            {action !== undefined && <div className="ir-mt-4">{action}</div>}
        </div>
    );
}

/** Initials avatar with a stable colour per name. */
export function Avatar({ name, className }: { name: string; className?: string }): ReactElement {
    const initials = name
        .split(/\s+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
    const hues = [243, 199, 160, 32, 340, 271, 12];
    const hue = hues[[...name].reduce((sum, char) => sum + char.charCodeAt(0), 0) % hues.length];

    return (
        <span
            className={cn('ir-flex ir-size-8 ir-shrink-0 ir-items-center ir-justify-center ir-rounded-full ir-text-xs ir-font-semibold', className)}
            style={{ background: `hsl(${hue} 70% 94%)`, color: `hsl(${hue} 55% 38%)` }}
        >
            {initials !== '' ? initials : '?'}
        </span>
    );
}

export interface MenuItem {
    label: string;
    icon?: React.ComponentType<{ className?: string }>;
    onSelect: () => void;
    danger?: boolean;
    disabled?: boolean;
    hint?: string;
}

/**
 * A "⋯" overflow menu: secondary actions live here instead of a row of buttons, so each
 * row shows only the one action that matters. Closes on outside click and Esc.
 */
export function ActionMenu({
    items,
    label = 'Más acciones',
    trigger,
    align = 'right',
    header,
    block = false,
}: {
    items: (MenuItem | null | false)[];
    label?: string;
    trigger?: ReactNode;
    align?: 'left' | 'right';
    /** Optional non-clickable top section (e.g. who is signed in). */
    header?: ReactNode;
    /** Stretch the trigger to its container's width. */
    block?: boolean;
}): ReactElement {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<{ top?: number; bottom?: number; left?: number; right?: number } | null>(null);
    const ref = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        const onDown = (event: MouseEvent): void => {
            if (ref.current !== null && !ref.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const onKey = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        // A fixed menu would drift away from its trigger on scroll/resize: close it instead.
        const close = (): void => setOpen(false);
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        window.addEventListener('scroll', close, true);
        window.addEventListener('resize', close);

        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('scroll', close, true);
            window.removeEventListener('resize', close);
        };
    }, [open]);

    const visible = items.filter((item): item is MenuItem => item !== null && item !== false);

    return (
        <div ref={ref} className={cn('ir-relative', block ? 'ir-flex ir-w-full' : 'ir-inline-flex')}>
            <button
                type="button"
                aria-label={label}
                title={label}
                aria-expanded={open}
                onClick={(event) => {
                    event.stopPropagation();
                    // Fixed positioning, measured from the trigger: the menu floats above any
                    // scrolling/clipping container (a list with its own scroll used to cut it off).
                    const rect = event.currentTarget.getBoundingClientRect();
                    // Open upwards when the trigger sits near the bottom of the screen (the
                    // account menu at the foot of the sidebar used to open off-screen).
                    const estimated = 48 * items.length + (header !== undefined ? 56 : 0) + 16;
                    const vertical = rect.bottom + estimated > window.innerHeight - 8 ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.bottom + 4 };
                    setPosition(
                        align === 'right'
                            ? { ...vertical, right: Math.max(8, window.innerWidth - rect.right) }
                            : { ...vertical, left: Math.max(8, rect.left) },
                    );
                    setOpen((current) => !current);
                }}
                className={
                    trigger !== undefined
                        ? (block ? 'ir-w-full' : undefined)
                        : 'ir-flex ir-size-8 ir-items-center ir-justify-center ir-rounded-lg ir-text-muted-foreground ir-transition-colors hover:ir-bg-muted hover:ir-text-foreground'
                }
            >
                {trigger ?? <MoreHorizontal className="ir-size-4" />}
            </button>
            {open && position !== null && (
                <div
                    role="menu"
                    style={{ position: 'fixed', top: position.top, bottom: position.bottom, left: position.left, right: position.right }}
                    className="ir-z-[60] ir-min-w-48 ir-max-w-[calc(100vw-1rem)] ir-overflow-hidden ir-rounded-xl ir-border ir-bg-card ir-p-1 ir-shadow-ir-lg ir-animate-in ir-fade-in-0 ir-zoom-in-95"
                >
                    {header !== undefined && <div className="ir-mb-1 ir-border-b ir-px-2.5 ir-pb-2 ir-pt-1.5">{header}</div>}
                    {visible.map((item) => {
                        const Icon = item.icon;

                        return (
                            <button
                                key={item.label}
                                type="button"
                                role="menuitem"
                                disabled={item.disabled}
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setOpen(false);
                                    item.onSelect();
                                }}
                                className={cn(
                                    'ir-flex ir-w-full ir-items-center ir-gap-2.5 ir-rounded-lg ir-px-2.5 ir-py-2 ir-text-left ir-text-sm ir-transition-colors disabled:ir-opacity-50',
                                    item.danger === true ? 'ir-text-danger hover:ir-bg-danger/10' : 'ir-text-foreground hover:ir-bg-muted',
                                )}
                            >
                                {Icon !== undefined && <Icon className="ir-size-4 ir-shrink-0 ir-opacity-70" />}
                                <span className="ir-min-w-0 ir-flex-1">
                                    <span className="ir-block ir-truncate">{item.label}</span>
                                    {item.hint !== undefined && <span className="ir-block ir-truncate ir-text-xs ir-text-muted-foreground">{item.hint}</span>}
                                </span>
                            </button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/** A coloured dot for a status (ok / warning / error / idle). */
export function StatusDot({ tone, pulse = false, className }: { tone: 'success' | 'warning' | 'danger' | 'neutral'; pulse?: boolean; className?: string }): ReactElement {
    const color = { success: 'ir-bg-success', warning: 'ir-bg-warning', danger: 'ir-bg-danger', neutral: 'ir-bg-muted-foreground/40' }[tone];

    return (
        <span className={cn('ir-relative ir-inline-flex ir-size-2 ir-shrink-0', className)}>
            {pulse && <span className={cn('ir-absolute ir-inset-0 ir-animate-ping ir-rounded-full ir-opacity-60', color)} />}
            <span className={cn('ir-relative ir-inline-flex ir-size-2 ir-rounded-full', color)} />
        </span>
    );
}
