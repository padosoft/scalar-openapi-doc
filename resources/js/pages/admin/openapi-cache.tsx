import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Head } from '@inertiajs/react';
import { toast } from 'sonner';

export default function OpenApiCachePage() {
    const clearCache = (): void => {
        router.delete('/openapi-cache', {
            onSuccess: () => {
                toast.success('OpenAPI cache cleared');
            },
            onError: () => {
                toast.error('Failed to clear cache');
            },
        });
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

                <Button type="button" variant="destructive" onClick={clearCache}>
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
