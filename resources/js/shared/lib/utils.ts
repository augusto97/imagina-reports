import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

// The SPAs namespace Tailwind with the `ir-` prefix (CLAUDE.md §6). tailwind-merge must
// know that prefix, otherwise it can't tell that e.g. `ir-max-w-lg` and `ir-max-w-5xl`
// conflict, and BOTH survive a merge — so className overrides silently don't apply.
const twMerge = extendTailwindMerge({ prefix: 'ir-' });

/** Merge Tailwind classes safely, with later conflicting classes winning (shadcn convention). */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
