import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { DataTable } from '@/components/data-table';

type Row = {
    id: number;
    name: string;
};

describe('DataTable', () => {
    it('renders rows and headers with custom renderers', () => {
        const rows = [
            { id: 1, name: 'alice' },
            { id: 2, name: 'bob' },
        ];

        render(
            <DataTable<Row>
                columns={[
                    { key: 'id', label: 'ID' },
                    { key: 'name', label: 'Name' },
                ]}
                rows={rows}
                rowKey="id"
            />,
        );

        expect(screen.getByText('ID')).toBeInTheDocument();
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('alice')).toBeInTheDocument();
        expect(screen.getByText('bob')).toBeInTheDocument();
    });

    it('shows an empty fallback when no rows exist', () => {
        render(
            <DataTable<Row>
                columns={[{ key: 'name', label: 'Name' }]}
                rows={[]}
                rowKey="id"
            />,
        );

        expect(screen.getByText('No rows.')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('uses custom renderer for row cells', () => {
        const rows = [{ id: 3, name: 'clara' }];
        render(
            <DataTable<Row>
                columns={[
                    {
                        key: 'name',
                        label: 'Upper Name',
                        render: (row) => row.name.toUpperCase(),
                    },
                ]}
                rows={rows}
                rowKey="id"
            />,
        );

        expect(screen.getByText('CLARA')).toBeInTheDocument();
        expect(screen.queryByText('clara')).not.toBeInTheDocument();
    });
});
