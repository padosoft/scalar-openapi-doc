import { Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { Head } from '@inertiajs/react';

type ServerRow = {
    id: number;
    url: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
};

type AdminServersProps = {
    servers: ServerRow[];
};

export default function AdminServersIndex() {
    const { servers } = usePage<AdminServersProps>().props;

    const columns = [
        {
            key: 'url' as const,
            label: 'Server URL',
        },
        {
            key: 'description' as const,
            label: 'Description',
            render: (row: ServerRow) => row.description ?? '—',
        },
        {
            key: 'sort_order' as const,
            label: 'Order',
        },
        {
            key: 'is_active' as const,
            label: 'Active',
            render: (row: ServerRow) => (row.is_active ? 'Yes' : 'No'),
        },
        {
            key: 'id' as const,
            label: 'Actions',
            render: (row: ServerRow) => (
                <div className="flex gap-2">
                    <Button asChild variant="outline">
                        <Link href={`/servers/${row.id}/edit`}>Edit</Link>
                    </Button>
                    <ConfirmDialog
                        title="Delete server"
                        description={`Delete ${row.url}?`}
                        onConfirm={() => router.delete(`/servers/${row.id}`)}
                    >
                        <Button type="button" variant="destructive">
                            Delete
                        </Button>
                    </ConfirmDialog>
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Servers" />
            <div className="mb-4 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Servers</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage playground server URLs injected into the OpenAPI spec.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/servers/create">Add server</Link>
                </Button>
            </div>

            <DataTable columns={columns} rows={servers} rowKey="id" />
        </>
    );
}

AdminServersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Servers',
            href: '/servers',
        },
    ],
};
