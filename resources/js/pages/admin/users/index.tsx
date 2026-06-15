import { Link, router, usePage } from '@inertiajs/react';
import { Head } from '@inertiajs/react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DataTable } from '@/components/data-table';
import { Button } from '@/components/ui/button';

type AdminUserRow = {
    id: number;
    name: string;
    email: string;
    role: string | null;
    roles: string[];
    grants: {
        tags: string[];
        endpoints: string[];
    };
};

type UsersIndexProps = {
    users: AdminUserRow[];
};

export default function UsersIndex() {
    const { users } = usePage<UsersIndexProps>().props;
    const onDelete = (user: AdminUserRow) => {
        router.delete(`/admin/users/${user.id}`);
    };

    const columns = [
        {
            key: 'name' as const,
            label: 'Name',
        },
        {
            key: 'email' as const,
            label: 'Email',
        },
        {
            key: 'role' as const,
            label: 'Role',
            render: (row: AdminUserRow) => row.role ?? '—',
        },
        {
            key: 'grants' as const,
            label: 'Grants',
            render: (row: AdminUserRow) => (
                <div>
                    <p>Tags: {row.grants.tags.join(', ') || '—'}</p>
                    <p>Endpoints: {row.grants.endpoints.length} selected</p>
                </div>
            ),
        },
        {
            key: 'id' as const,
            label: 'Actions',
            render: (row: AdminUserRow) => (
                <div className="space-x-2">
                    <Button asChild variant="outline">
                        <Link href={`/admin/users/${row.id}/edit`}>Edit</Link>
                    </Button>
                    <ConfirmDialog
                        title="Delete user"
                        description={`Remove ${row.name} from the team? This action cannot be undone.`}
                        onConfirm={() => onDelete(row)}
                        destructive
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
            <Head title="Users" />
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Users</h1>
                    <p className="text-sm text-muted-foreground">
                        Manage API access for admins and viewer users.
                    </p>
                </div>
                <Button asChild>
                    <Link href="/admin/users/create">Create user</Link>
                </Button>
            </div>

            <DataTable
                columns={columns}
                rows={users}
                rowKey="id"
                emptyMessage="No users available."
            />
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: '/admin/users',
        },
    ],
};
