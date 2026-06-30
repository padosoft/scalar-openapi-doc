import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import InputError from '@/components/input-error';
import { MultiSelect } from '@/components/multi-select';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type UserPayload = {
    id?: number;
    name: string;
    email: string;
    role: string | null;
    grants?: {
        tags: string[];
        endpoints: string[];
        servers: string[];
    };
};

type GrantsCatalog = {
    tags: Array<{ value: string; label: string }>;
    endpoints: Array<{ value: string; label: string }>;
};

type ServerOption = { value: string; label: string };

type OpenApiFailure = {
    category: string;
    label: string;
    httpStatus: number | null;
    exceptionClass: string;
    message: string;
};

type OpenApiStatus = {
    ok: boolean;
    failure?: OpenApiFailure;
};

type UserFormProps = {
    user: UserPayload | null;
    roles: string[];
    openapi: GrantsCatalog;
    openapiStatus: OpenApiStatus;
    servers: ServerOption[];
};

export default function UserForm() {
    const { user, roles, openapi, openapiStatus, servers } =
        usePage<UserFormProps>().props;
    const isEdit = user !== null;
    const openapiFailure =
        openapiStatus.ok === false ? openapiStatus.failure : undefined;

    const endpointOptions = useMemo(
        () =>
            openapi.endpoints.map((endpoint) => ({
                value: endpoint.value,
                label: endpoint.label,
            })),
        [openapi],
    );

    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        password: '',
        role: user?.role ?? roles[0] ?? 'admin',
        grants: {
            tags: user?.grants?.tags ?? [],
            endpoints: user?.grants?.endpoints ?? [],
            servers: user?.grants?.servers ?? [],
        },
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/admin/users/${user.id}`, {
                onSuccess: () => {
                    router.visit('/admin/users');
                },
            });

            return;
        }

        form.post('/admin/users', {
            onSuccess: () => {
                router.visit('/admin/users');
            },
        });
    };

    return (
        <>
            <Head title={isEdit ? 'Edit user' : 'Create user'} />

            <h1 className="text-2xl font-semibold">
                {isEdit ? 'Edit user' : 'Create user'}
            </h1>

            <form onSubmit={submit} className="mt-6 max-w-2xl space-y-6">
                <div className="space-y-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        name="name"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.currentTarget.value)
                        }
                        required
                        autoComplete="name"
                    />
                    <InputError message={form.errors.name} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.currentTarget.value)
                        }
                        required
                        autoComplete="email"
                    />
                    <InputError message={form.errors.email} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="password">Password</Label>
                    <Input
                        id="password"
                        name="password"
                        type="password"
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.currentTarget.value)
                        }
                        required={!isEdit}
                        autoComplete={isEdit ? 'new-password' : 'new-password'}
                    />
                    <InputError message={form.errors.password} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="role">Role</Label>
                    <select
                        id="role"
                        name="role"
                        value={form.data.role}
                        onChange={(event) =>
                            form.setData('role', event.currentTarget.value)
                        }
                        className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm outline-none"
                    >
                        {roles.map((role) => (
                            <option value={role} key={role}>
                                {role}
                            </option>
                        ))}
                    </select>
                    <InputError message={form.errors.role} />
                </div>

                {openapiFailure && (
                    <Alert
                        variant="destructive"
                        data-testid="openapi-status-warning"
                    >
                        <AlertTitle>
                            API documentation options unavailable
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                Tag and endpoint options couldn&rsquo;t be
                                loaded, so only this user&rsquo;s existing
                                grants are shown. You can still edit the other
                                fields and save &mdash; grants are re-validated
                                on the server.
                            </p>
                            <p className="text-xs">
                                Reason: {openapiFailure.label}
                                {openapiFailure.httpStatus !== null &&
                                    ` (HTTP ${openapiFailure.httpStatus})`}
                                {' — '}
                                {openapiFailure.exceptionClass}
                                {openapiFailure.message &&
                                    `: ${openapiFailure.message}`}
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                <MultiSelect
                    label="Granted tags"
                    options={openapi.tags.map((tag) => ({
                        value: tag.value,
                        label: tag.label,
                    }))}
                    selected={form.data.grants.tags}
                    onChange={(grants) =>
                        form.setData('grants', {
                            ...form.data.grants,
                            tags: grants,
                        })
                    }
                    emptyLabel="No tags selected"
                />
                <InputError message={form.errors['grants.tags']} />

                <MultiSelect
                    label="Granted endpoints"
                    options={endpointOptions}
                    selected={form.data.grants.endpoints}
                    onChange={(grants) =>
                        form.setData('grants', {
                            ...form.data.grants,
                            endpoints: grants,
                        })
                    }
                    emptyLabel="No endpoints selected"
                />
                <InputError message={form.errors['grants.endpoints']} />

                <MultiSelect
                    label="Granted servers"
                    options={servers}
                    selected={form.data.grants.servers}
                    onChange={(grants) =>
                        form.setData('grants', {
                            ...form.data.grants,
                            servers: grants,
                        })
                    }
                    emptyLabel="No servers selected"
                />
                <InputError message={form.errors['grants.servers']} />

                <div className="flex items-center gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {isEdit ? 'Save changes' : 'Create user'}
                    </Button>
                </div>
            </form>
        </>
    );
}

UserForm.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: '/admin/users',
        },
        {
            title: 'User form',
            href: '/admin/users',
        },
    ],
};
