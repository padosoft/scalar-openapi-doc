import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { MultiSelect } from '@/components/multi-select';

const endpoints = [
    { value: 'GET /orders', label: 'GET /orders' },
    { value: 'POST /orders', label: 'POST /orders' },
];

describe('MultiSelect', () => {
    it('shows an empty-state summary', () => {
        render(
            <MultiSelect
                label="Granted endpoints"
                options={endpoints}
                selected={[]}
                onChange={vi.fn()}
            />,
        );

        expect(screen.getByText('No items selected')).toBeInTheDocument();
        expect(screen.queryByText('GET /orders')).not.toBeInTheDocument();
    });

    it('emits selected values and toggles correctly', () => {
        const onChange = vi.fn();
        render(
            <MultiSelect
                label="Granted endpoints"
                options={endpoints}
                selected={['GET /orders']}
                onChange={onChange}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Granted endpoints' }),
        );
        expect(screen.getByText('POST /orders')).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText('POST /orders'));
        expect(onChange).toHaveBeenLastCalledWith([
            'GET /orders',
            'POST /orders',
        ]);
        fireEvent.click(screen.getByLabelText('GET /orders'));
        expect(onChange).toHaveBeenLastCalledWith([]);
    });
});
