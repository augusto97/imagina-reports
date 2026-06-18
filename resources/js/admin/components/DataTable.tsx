import { type ColumnDef, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { type ReactElement } from 'react';

/** Thin wrapper over TanStack Table for the admin lists (CLAUDE.md §11.1). */
export function DataTable<T>({ columns, data }: { columns: ColumnDef<T>[]; data: T[] }): ReactElement {
    const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });

    return (
        <table className="ir-w-full ir-text-left ir-text-sm">
            <thead>
                {table.getHeaderGroups().map((group) => (
                    <tr key={group.id} className="ir-text-muted-foreground">
                        {group.headers.map((header) => (
                            <th key={header.id} className="ir-py-2 ir-font-medium">
                                {header.isPlaceholder
                                    ? null
                                    : flexRender(header.column.columnDef.header, header.getContext())}
                            </th>
                        ))}
                    </tr>
                ))}
            </thead>
            <tbody>
                {table.getRowModel().rows.map((row) => (
                    <tr key={row.id} className="ir-border-t">
                        {row.getVisibleCells().map((cell) => (
                            <td key={cell.id} className="ir-py-2">
                                {flexRender(cell.column.columnDef.cell, cell.getContext())}
                            </td>
                        ))}
                    </tr>
                ))}
                {data.length === 0 && (
                    <tr>
                        <td colSpan={columns.length} className="ir-py-4 ir-text-muted-foreground">
                            Sin datos todavía.
                        </td>
                    </tr>
                )}
            </tbody>
        </table>
    );
}
