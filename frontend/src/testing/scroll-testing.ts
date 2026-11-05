import { ElementScrollHost } from '../app/shared/scroll-host/scroll-host';

/** Make an element's scrollTop a plain settable value, since jsdom does no layout. */
export function trackScrollTop(element: HTMLElement): { top: number } {
  const state = { top: 0 };
  Object.defineProperty(element, 'scrollTop', {
    configurable: true,
    get: () => state.top,
    set: (value: number) => {
      state.top = value;
    },
  });
  return state;
}

export function scrollerOf(fixture: { nativeElement: unknown }): HTMLElement {
  return (fixture.nativeElement as HTMLElement).querySelector('.scroller') as HTMLElement;
}

/** jsdom ships no `CSS` namespace; the ids under test need no escaping. Returns the restore. */
export function stubCssEscape(): () => void {
  const globals = globalThis as unknown as { CSS?: { escape: (value: string) => string } };
  const previous = globals.CSS;
  globals.CSS = { escape: (value) => value };
  return () => {
    globals.CSS = previous;
  };
}

export function elementScrollHost(element: HTMLElement): ElementScrollHost {
  return new ElementScrollHost(element, (run) => run());
}
