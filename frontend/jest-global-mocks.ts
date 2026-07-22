// jest-global-mocks.ts
// jsdom lacks matchMedia (ThemeService) and, in some Node versions, an
// exposed crypto.subtle (ALTCHA solver). Provide both for tests.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    addListener: () => undefined,
    removeListener: () => undefined,
    dispatchEvent: () => false,
  }),
});

if (!globalThis.crypto?.subtle) {
  // Node's WebCrypto, exposed under the same API the browser uses.
  const { webcrypto } = require('node:crypto');
  Object.defineProperty(globalThis, 'crypto', { value: webcrypto });
}

// Minimal IntersectionObserver stub — jsdom has none. Components only need it
// to construct without throwing; tests exercise the Load-more button directly.
class IntersectionObserverStub {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
  takeRecords(): [] {
    return [];
  }
}
(globalThis as unknown as { IntersectionObserver: unknown }).IntersectionObserver =
  IntersectionObserverStub;
