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
