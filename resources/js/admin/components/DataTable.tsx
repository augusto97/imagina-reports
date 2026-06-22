import { type ColumnDef, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { type ReactElement } from 'react';

/** Thin wrapper over TanStack Table for the admin lists (CLAUDE.md §11.1). */
export function DataTable<T>({ columns, data }: { columns: ColumnDef<T>[]; data: T[] }): ReactElement {
    const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });

    return (
        <div className="ir-overflow-x-auto">
            <table className="ir-w-full ir-border-collapse ir-text-left ir-text-sm">
                <thead>
                    {table.getHeaderGroups().map((group) => (
                        <tr key={group.id} className="ir-border-b">
                            {group.headers.map((header) => (
                                <th
                                    key={header.id}
                                    className="ir-whitespace-nowrap ir-px-3 ir-py-2.5 ir-text-xs ir-font-semibold ir-uppercase ir-tracking-wide ir-text-muted-foreground first:ir-pl-1"
                                >
                                    {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                </th>
                            ))}
                        </tr>
                    ))}
                </thead>
                <tbody>
                    {table.getRowModel().rows.map((row) => (
                        <tr key={row.id} className="ir-border-b ir-border-border/60 ir-transition-colors hover:ir-bg-muted/50">
                            {row.getVisibleCells().map((cell) => (
                                <td key={cell.id} className="ir-px-3 ir-py-3 ir-align-middle first:ir-pl-1">
                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {data.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="ir-px-3 ir-py-10 ir-text-center ir-text-muted-foreground">
                                Sin datos todavía.
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    );
}
