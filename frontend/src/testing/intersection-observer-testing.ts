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

/** An IntersectionObserver double that records its targets and reports an intersection ratio on demand, to the observers watching each target. */
export class MockIntersectionObserver {
  static instances: MockIntersectionObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(
    readonly callback: IntersectionObserverCallback,
    readonly options?: IntersectionObserverInit,
  ) {
    MockIntersectionObserver.instances.push(this);
  }
  observe(target: Element): void {
    this.targets.add(target);
  }
  unobserve(target: Element): void {
    this.targets.delete(target);
  }
  disconnect(): void {
    this.targets.clear();
  }

  static observes(target: Element): boolean {
    return MockIntersectionObserver.instances.some((observer) => observer.targets.has(target));
  }

  /** Reports `target` at `intersectionRatio` to every observer watching it. */
  static report(target: Element, intersectionRatio: number): void {
    const change = {
      target,
      intersectionRatio,
      isIntersecting: intersectionRatio > 0,
    } as unknown as IntersectionObserverEntry;
    for (const observer of MockIntersectionObserver.instances) {
      if (!observer.targets.has(target)) continue;
      observer.callback([change], observer as unknown as IntersectionObserver);
    }
  }
}

/** Installs a fresh `MockIntersectionObserver`; undo with `restoreIntersectionObserver`. */
export function installMockIntersectionObserver(): void {
  MockIntersectionObserver.instances = [];
  globalThis.IntersectionObserver =
    MockIntersectionObserver as unknown as typeof IntersectionObserver;
}
