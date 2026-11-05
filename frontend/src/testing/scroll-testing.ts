import { Injectable } from '@angular/core';
import { ElementScrollHost } from '../app/shared/scroll-host/scroll-host';
import type { ScrollHost } from '../app/shared/scroll-host/scroll-host';
import { ScrollHosts } from '../app/shared/scroll-host/scroll-hosts.service';

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

/** For specs that drive the list's scroller as an element: jsdom has no page scroll (#2038). */
@Injectable()
export class ElementBackedScrollHosts extends ScrollHosts {
  override forPage(content: HTMLElement): ScrollHost {
    return this.forElement(content);
  }
}
