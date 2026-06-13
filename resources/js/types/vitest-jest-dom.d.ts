// Brings the @testing-library/jest-dom custom matchers (toBeInTheDocument, …)
// into the TypeScript scope for Vitest's `expect`. The runtime augmentation is
// registered in vitest.setup.ts; this declaration makes the matchers typed.
import '@testing-library/jest-dom/vitest';
