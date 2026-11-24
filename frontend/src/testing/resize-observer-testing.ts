/** A ResizeObserver double that records its targets and fires on demand. */
export class MockResizeObserver {
  static instances: MockResizeObserver[] = [];
  readonly targets = new Set<Element>();
  constructor(readonly callback: ResizeObserverCallback) {
    MockResizeObserver.instances.push(this);
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
  fire(): void {
    this.callback([], this as unknown as ResizeObserver);
  }
}

/** Replace the global ResizeObserver with a fresh, empty `MockResizeObserver`. */
export function installMockResizeObserver(): void {
  MockResizeObserver.instances = [];
  (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = MockResizeObserver;
}

/** Give `element` a laid-out height and fire its observer, as a real resize would. */
export function resizeTo(element: HTMLElement, height: number): void {
  Object.defineProperty(element, 'offsetHeight', { configurable: true, value: height });
  fireResizeOf(element);
}

/** Fire the mock observer watching `element`, as a resize of it would. */
export function fireResizeOf(element: Element): void {
  MockResizeObserver.instances.find((observer) => observer.targets.has(element))?.fire();
}
