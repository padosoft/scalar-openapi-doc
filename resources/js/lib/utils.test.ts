import { describe, expect, it } from 'vitest';
import { cn, toUrl } from '@/lib/utils';

describe('cn', () => {
    it('joins truthy class names', () => {
        expect(cn('a', 'b')).toBe('a b');
    });

    it('drops falsy values', () => {
        expect(cn('a', false, null, undefined, 'b')).toBe('a b');
    });

    it('merges conflicting tailwind classes (last wins)', () => {
        expect(cn('px-2', 'px-4')).toBe('px-4');
    });
});

describe('toUrl', () => {
    it('returns a string href as-is', () => {
        expect(toUrl('/dashboard')).toBe('/dashboard');
    });

    it('extracts the url from an object href', () => {
        expect(toUrl({ url: '/admin/users', method: 'get' })).toBe(
            '/admin/users',
        );
    });
});
