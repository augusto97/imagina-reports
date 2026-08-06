import { ChevronLeft, ChevronRight } from "lucide-react";
import { type ReactElement, useState } from "react";

import { cn } from "@shared/lib/utils";

/** `YYYY-MM` for the month we're in — the one the grid marks as "today". */
function currentMonth(): string {
    const now = new Date();

    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

const MONTH_NAMES = [
    "ene",
    "feb",
    "mar",
    "abr",
    "may",
    "jun",
    "jul",
    "ago",
    "sep",
    "oct",
    "nov",
    "dic",
];

/**
 * Pick a month in one click.
 *
 * Not a native `<input type="month">`: that renders as a text field whose picker needs a
 * second click on its own little calendar indicator — so choosing a different month cost
 * three interactions, and its behaviour is the browser's, not ours (Safari doesn't support
 * the type at all and falls back to a plain text box). A report period is always a whole
 * month, so a year stepper over twelve buttons is both simpler and shorter.
 */
export function MonthPicker({
    value,
    onPick,
}: {
    value: string;
    onPick: (month: string) => void;
}): ReactElement {
    const today = currentMonth();
    const [year, setYear] = useState(() =>
        Number((value === "" ? today : value).slice(0, 4)),
    );

    const step = (delta: number): void => setYear((current) => current + delta);

    return (
        <div>
            <div className="ir-mb-2 ir-flex ir-items-center ir-justify-between">
                <button
                    type="button"
                    onClick={() => step(-1)}
                    aria-label="Año anterior"
                    className="ir-rounded-md ir-p-1 ir-text-muted-foreground ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                >
                    <ChevronLeft className="ir-size-4" />
                </button>
                <span className="ir-text-sm ir-font-semibold ir-tabular-nums">
                    {year}
                </span>
                <button
                    type="button"
                    onClick={() => step(1)}
                    aria-label="Año siguiente"
                    className="ir-rounded-md ir-p-1 ir-text-muted-foreground ir-transition hover:ir-bg-muted hover:ir-text-foreground"
                >
                    <ChevronRight className="ir-size-4" />
                </button>
            </div>
            <div className="ir-grid ir-grid-cols-3 ir-gap-1">
                {MONTH_NAMES.map((label, index) => {
                    const key = `${year}-${String(index + 1).padStart(2, "0")}`;

                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() => onPick(key)}
                            className={cn(
                                "ir-rounded-md ir-py-1.5 ir-text-xs ir-font-medium ir-capitalize ir-transition",
                                key === value
                                    ? "ir-bg-accent ir-text-accent-foreground"
                                    : key === today
                                      ? "ir-bg-muted ir-text-foreground"
                                      : "ir-text-muted-foreground hover:ir-bg-muted hover:ir-text-foreground",
                            )}
                        >
                            {label}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
