import { Head } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

export default function OpenApiCachePage() {
    const clearCache = async (): Promise<void> => {
        const csrfToken = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content');

        if (!csrfToken) {
            toast.error('Unable to find CSRF token');

            return;
        }

        const response = await fetch('/openapi-cache', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            toast.error('Failed to clear cache');

            return;
        }

        await response.json();
        toast.success('OpenAPI cache cleared');
    };

    return (
        <>
            <Head title="OpenAPI Cache" />
            <section className="space-y-4">
                <div>
                    <h1 className="text-2xl font-semibold">OpenAPI Cache</h1>
                    <p className="text-sm text-muted-foreground">
                        Clear the server-side OpenAPI document cache and stale
                        fallback copy.
                    </p>
                </div>

                <Button
                    type="button"
                    variant="destructive"
                    onClick={clearCache}
                >
                    Flush cache
                </Button>
            </section>
        </>
    );
}

OpenApiCachePage.layout = {
    breadcrumbs: [
        {
            title: 'OpenAPI Cache',
            href: '/openapi-cache',
        },
    ],
};
