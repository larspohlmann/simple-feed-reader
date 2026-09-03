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

// jsdom's Blob/File has no text(); the catalog importer reads the chosen file
// with it. Resolve synchronously from the buffer jsdom keeps internally so the
// promise settles as a microtask — a real FileReader load is an untracked
// macrotask that ComponentFixture.whenStable() would race past.
if (typeof Blob !== 'undefined' && typeof Blob.prototype.text !== 'function') {
  Blob.prototype.text = function readBlobText(this: Blob): Promise<string> {
    const implSymbol = Object.getOwnPropertySymbols(this).find(
      (symbol) => symbol.toString() === 'Symbol(impl)',
    );
    const impl = implSymbol
      ? (this as unknown as Record<symbol, { _buffer?: Buffer }>)[implSymbol]
      : undefined;
    return Promise.resolve(impl?._buffer?.toString('utf8') ?? '');
  };
}

// CDK's focus trap calls it "not focusable" whenever the element reports no
// geometry, and jsdom computes no layout -- so every genuinely focusable
// cdkFocusInitial input/button trips this dev-mode warning. Drop that one
// known-false line; every other warning passes through.
const warnAboutRealProblems = console.warn.bind(console);
console.warn = (...args: unknown[]): void => {
  if (typeof args[0] === 'string' && args[0].includes("'[cdkFocusInitial]' is not focusable")) {
    return;
  }
  warnAboutRealProblems(...args);
};
