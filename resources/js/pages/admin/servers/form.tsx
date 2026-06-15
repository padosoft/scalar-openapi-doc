import { Head, useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import InputError from '@/components/input-error';

type ServerPayload = {
    id?: number;
    url: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
};

type ServerFormProps = {
    server: ServerPayload | null;
};

export default function AdminServersForm() {
    const { server } = usePage<ServerFormProps>().props;
    const isEdit = server !== null;

    const form = useForm({
        url: server?.url ?? '',
        description: server?.description ?? '',
        sort_order: server?.sort_order ?? 0,
        is_active: server?.is_active ?? true,
    });

    const submit = (event: React.FormEvent): void => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/servers/${server?.id}`);
            return;
        }

        form.post('/servers');
    };

    return (
        <>
            <Head title={isEdit ? 'Edit server' : 'Create server'} />
            <h1 className="text-2xl font-semibold">
                {isEdit ? 'Edit server' : 'Create server'}
            </h1>

            <form onSubmit={submit} className="mt-6 max-w-xl space-y-6">
                <div className="space-y-2">
                    <Label htmlFor="url">Server URL</Label>
                    <Input
                        id="url"
                        name="url"
                        value={form.data.url}
                        onChange={(event) => form.setData('url', event.currentTarget.value)}
                        required
                    />
                    <InputError message={form.errors.url} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="description">Description</Label>
                    <Input
                        id="description"
                        name="description"
                        value={form.data.description}
                        onChange={(event) => form.setData('description', event.currentTarget.value)}
                    />
                    <InputError message={form.errors.description} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="sort_order">Sort order</Label>
                    <Input
                        id="sort_order"
                        name="sort_order"
                        type="number"
                        min={0}
                        value={form.data.sort_order}
                        onChange={(event) =>
                            form.setData('sort_order', Number(event.currentTarget.value))
                        }
                        required
                    />
                    <InputError message={form.errors.sort_order} />
                </div>

                <div className="space-y-2">
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={form.data.is_active}
                            onCheckedChange={(value) =>
                                form.setData('is_active', Boolean(value))
                            }
                        />
                        Active
                    </label>
                    <InputError message={form.errors.is_active} />
                </div>

                <Button type="submit" disabled={form.processing}>
                    {isEdit ? 'Save changes' : 'Create server'}
                </Button>
            </form>
        </>
    );
}

AdminServersForm.layout = {
    breadcrumbs: [
        {
            title: 'Servers',
            href: '/servers',
        },
        {
            title: 'Server form',
            href: '/servers/create',
        },
    ],
};
