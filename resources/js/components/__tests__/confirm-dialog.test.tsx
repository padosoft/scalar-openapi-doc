import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmDialog } from '@/components/confirm-dialog';

describe('ConfirmDialog', () => {
    it('opens from trigger and runs confirm action', () => {
        const onConfirm = vi.fn();
        render(
            <ConfirmDialog
                title="Delete user"
                description="This action cannot be undone."
                onConfirm={onConfirm}
            >
                <button type="button">Delete</button>
            </ConfirmDialog>,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Delete user')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Confirm' }));
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('displays the custom labels and cancel action', () => {
        render(
            <ConfirmDialog
                title="Reset token"
                description="Clear authentication tokens"
                confirmLabel="Reset"
                cancelLabel="Back"
                onConfirm={() => null}
            >
                <button type="button">Open</button>
            </ConfirmDialog>,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Open' }));
        expect(screen.getByRole('button', { name: 'Back' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reset' })).toBeInTheDocument();
    });
});

