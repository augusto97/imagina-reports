import { forwardRef, type ReactElement, type ReactNode } from 'react';

import { cn } from '@shared/lib/utils';

export function Button({
    className,
    variant = 'primary',
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'ghost' }): ReactElement {
    return (
        <button
            className={cn(
                'ir-inline-flex ir-items-center ir-gap-2 ir-rounded-md ir-px-3 ir-py-2 ir-text-sm ir-font-medium ir-transition disabled:ir-opacity-50',
                variant === 'primary' && 'ir-bg-primary ir-text-primary-foreground hover:ir-opacity-90',
                variant === 'ghost' && 'ir-border ir-bg-background hover:ir-bg-muted',
                className,
            )}
            {...props}
        />
    );
}

// forwardRef so React Hook Form's `register` ref attaches to the real <input> —
// without it, autofilled values aren't captured (form thinks the field is empty).
export const Input = forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    function Input({ className, ...props }, ref): ReactElement {
        return (
            <input
                ref={ref}
                className={cn(
                    'ir-w-full ir-rounded-md ir-border ir-bg-background ir-px-3 ir-py-2 ir-text-sm ir-outline-none focus:ir-ring-2 focus:ir-ring-ring',
                    className,
                )}
                {...props}
            />
        );
    },
);

export function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}): ReactElement {
    return (
        <label className="ir-flex ir-flex-col ir-gap-1">
            <span className="ir-text-xs ir-font-medium ir-text-muted-foreground">{label}</span>
            {children}
            {error !== undefined && <span className="ir-text-xs ir-text-red-500">{error}</span>}
        </label>
    );
}

export function Card({ title, children }: { title?: string; children: ReactNode }): ReactElement {
    return (
        <section className="ir-rounded-lg ir-border ir-bg-card ir-p-6">
            {title !== undefined && <h2 className="ir-mb-4 ir-text-sm ir-font-semibold">{title}</h2>}
            {children}
        </section>
    );
}
