/** An IntersectionObserver double that records its targets and reports visibility on demand. */
export class MockIntersectionObserver {
  static instances: MockIntersectionObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: IntersectionObserverCallback) {
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

/** Replaces the global IntersectionObserver with a fresh `MockIntersectionObserver`; returns the restore. */
export function installMockIntersectionObserver(): () => void {
  const real = globalThis.IntersectionObserver;
  MockIntersectionObserver.instances = [];
  globalThis.IntersectionObserver =
    MockIntersectionObserver as unknown as typeof IntersectionObserver;
  return () => (globalThis.IntersectionObserver = real);
}
