const realIntersectionObserver = globalThis.IntersectionObserver;

/** An IntersectionObserver double that records its targets and reports sightings on demand. */
export function stubIntersectionObserver() {
  const callbacks: IntersectionObserverCallback[] = [];
  const observed: Element[] = [];
  const disconnect = jest.fn();
  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      callbacks.push(callback);
    }
    readonly observe = (target: Element) => observed.push(target);
    readonly disconnect = disconnect;
  } as unknown as typeof IntersectionObserver;
  const report = (target: Element, isIntersecting: boolean) =>
    callbacks[0](
      [{ target, isIntersecting } as unknown as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );
  return { observed, report, disconnect };
}

/** Puts back the global stub that jest-global-mocks installs. */
export function restoreIntersectionObserver(): void {
  globalThis.IntersectionObserver = realIntersectionObserver;
}
