import type { ReactNode } from 'react';

type DataTableColumn<T> = {
    key: keyof T;
    label: string;
    className?: string;
    render?: (row: T, value: T[keyof T]) => ReactNode;
};

type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: keyof T | ((row: T, index: number) => string);
    emptyMessage?: string;
};

export function DataTable<T>({
    columns,
    rows,
    rowKey,
    emptyMessage = 'No rows.',
}: DataTableProps<T>): ReactNode {
    const resolveRowKey = (row: T, index: number): string =>
        typeof rowKey === 'function' ? rowKey(row, index) : String(row[rowKey]);

    if (rows.length === 0) {
        return (
            <p className="px-1 py-6 text-sm text-muted-foreground">
                {emptyMessage}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        {columns.map((column) => (
                            <th
                                key={String(column.key)}
                                className={`px-3 py-2 font-semibold ${column.className ?? ''}`}
                            >
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr
                            key={resolveRowKey(row, index)}
                            className="border-b last:border-b-0"
                        >
                            {columns.map((column) => {
                                const value = row[column.key];

                                return (
                                    <td
                                        key={String(column.key)}
                                        className={`px-3 py-2 align-top ${column.className ?? ''}`}
                                    >
                                        {column.render
                                            ? column.render(row, value)
                                            : (value as ReactNode)}
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

