import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

// Smoke test proving the jsdom environment, React render pipeline, and
// jest-dom matchers are wired correctly. Real component tests (MultiSelect,
// DataTable, role-conditional nav) land with their features.
function Greeting({ name }: { name: string }) {
    return <p>Hello {name}</p>;
}

describe('vitest + testing-library setup', () => {
    it('renders a component into jsdom', () => {
        render(<Greeting name="Scalar" />);
        expect(screen.getByText('Hello Scalar')).toBeInTheDocument();
    });
});
