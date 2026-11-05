import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { PageChromeGeometry } from './page-chrome-geometry';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../testing/resize-observer-testing';

function boxed(rect: Partial<DOMRect>, tag = 'div'): HTMLElement {
  const element = document.createElement(tag);
  element.getBoundingClientRect = () => rect as DOMRect;
  return element;
}

const observing = (element: Element): MockResizeObserver | undefined =>
  MockResizeObserver.instances.find((observer) => observer.targets.has(element));

describe('PageChromeGeometry', () => {
  beforeEach(() => installMockResizeObserver());

  function publish(parts: { column?: HTMLElement; main?: HTMLElement; audioBar?: HTMLElement }) {
    const host = document.createElement('div');
    const column = signal(parts.column);
    TestBed.runInInjectionContext(
      () =>
        new PageChromeGeometry({
          host,
          listColumn: column,
          main: () => parts.main,
          audioBar: () => parts.audioBar,
        }),
    );
    TestBed.tick();
    return { host, column };
  }

  it('publishes the list column box for the fixed list chrome', () => {
    const column = boxed({ left: 312, width: 560 });
    const { host } = publish({ column });
    observing(column)!.fire();
    expect(host.style.getPropertyValue('--list-column-left')).toBe('312px');
    expect(host.style.getPropertyValue('--list-column-width')).toBe('560px');
  });

  it('re-measures the column when the main area resizes around it', () => {
    let left = 312;
    const column = document.createElement('section');
    column.getBoundingClientRect = () => ({ left, width: 280 }) as DOMRect;
    const main = boxed({}, 'main');
    const { host } = publish({ column, main });

    left = 8;
    observing(main)!.fire();

    expect(host.style.getPropertyValue('--list-column-left')).toBe('8px');
  });

  it('follows the column the layout renders next', () => {
    const first = boxed({ left: 0, width: 375 });
    const second = boxed({ left: 312, width: 560 });
    const { host, column } = publish({ column: first });

    column.set(second);
    TestBed.tick();
    observing(second)!.fire();

    expect(observing(first)).toBeUndefined();
    expect(host.style.getPropertyValue('--list-column-left')).toBe('312px');
  });

  it('publishes the audio bar height the page leaves free', () => {
    const audioBar = boxed({ height: 72 });
    const { host } = publish({ audioBar });
    observing(audioBar)!.fire();
    expect(host.style.getPropertyValue('--audio-bar-h')).toBe('72px');
  });
});
