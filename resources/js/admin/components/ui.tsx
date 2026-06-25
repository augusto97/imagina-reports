import { forwardRef, type ReactElement, type ReactNode, useEffect } from 'react';

import { cn } from '@shared/lib/utils';

type ButtonVariant = 'primary' | 'accent' | 'outline' | 'ghost' | 'subtle' | 'danger';
type ButtonSize = 'sm' | 'md';

const BUTTON_VARIANTS: Record<ButtonVariant, string> = {
    primary: 'ir-bg-primary ir-text-primary-foreground ir-shadow-ir-xs hover:ir-opacity-90',
    accent: 'ir-bg-accent ir-text-accent-foreground ir-shadow-ir-xs hover:ir-opacity-90',
    outline: 'ir-border ir-bg-card ir-text-foreground ir-shadow-ir-xs hover:ir-bg-muted',
    // ghost keeps a subtle border (it's the admin's default secondary button).
    ghost: 'ir-border ir-bg-card ir-text-foreground ir-shadow-ir-xs hover:ir-bg-muted',
    subtle: 'ir-bg-muted ir-text-foreground hover:ir-bg-muted/70',
    danger: 'ir-bg-danger ir-text-white ir-shadow-ir-xs hover:ir-opacity-90',
};

const BUTTON_SIZES: Record<ButtonSize, string> = {
    sm: 'ir-h-8 ir-px-2.5 ir-text-xs',
    md: 'ir-h-9 ir-px-3.5 ir-text-sm',
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
                'ir-inline-flex ir-items-center ir-justify-center ir-gap-1.5 ir-rounded-md ir-font-medium ir-transition-colors',
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
    'ir-h-9 ir-w-full ir-rounded-md ir-border ir-bg-card ir-px-3 ir-text-sm ir-shadow-ir-xs ir-outline-none ir-transition placeholder:ir-text-muted-foreground/70 focus:ir-border-accent focus:ir-ring-2 focus:ir-ring-ring/40 disabled:ir-opacity-50';

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
        <section className={cn('ir-rounded-lg ir-border ir-bg-card ir-shadow-ir-sm', className)}>
            {(title !== undefined || actions !== undefined) && (
                <header className="ir-flex ir-flex-wrap ir-items-start ir-justify-between ir-gap-3 ir-border-b ir-px-5 ir-py-4">
                    <div>
                        {title !== undefined && <h2 className="ir-text-sm ir-font-semibold ir-tracking-tight">{title}</h2>}
                        {description !== undefined && <p className="ir-mt-0.5 ir-text-xs ir-text-muted-foreground">{description}</p>}
                    </div>
                    {actions !== undefined && <div className="ir-flex ir-shrink-0 ir-items-center ir-gap-2">{actions}</div>}
                </header>
            )}
            <div className="ir-p-5">{children}</div>
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
        <div className="ir-fixed ir-inset-0 ir-z-50 ir-flex ir-items-start ir-justify-center ir-overflow-y-auto ir-bg-black/40 ir-p-4 ir-pt-[8vh]">
            <button type="button" aria-label="Cerrar" onClick={onClose} className="ir-fixed ir-inset-0 ir-cursor-default" />
            <div className={cn('ir-relative ir-z-10 ir-w-full ir-max-w-lg', className)}>{children}</div>
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
