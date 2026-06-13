import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Unmount React trees and clear the jsdom body after every test.
afterEach(() => {
    cleanup();
});
