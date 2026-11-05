import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ElementScrollHost } from './scroll-host';
import { ScrollHosts } from './scroll-hosts.service';
import { trackScrollTop } from '../../../testing/scroll-testing';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../../testing/resize-observer-testing';

const inZone = <T>(run: () => T): T => run();

describe('ElementScrollHost', () => {
  it('reads and writes the element scroll offset', () => {
    const element = document.createElement('div');
    trackScrollTop(element);
    const host = new ElementScrollHost(element, inZone);
    host.scrollTop = 120;
    expect(element.scrollTop).toBe(120);
    expect(host.scrollTop).toBe(120);
  });

  it('reports the element as content and as the intersection root', () => {
    const element = document.createElement('div');
    const host = new ElementScrollHost(element, inZone);
    expect(host.content).toBe(element);
    expect(host.intersectionRoot).toBe(element);
  });

  it('measures from the element box', () => {
    const element = document.createElement('div');
    Object.defineProperty(element, 'clientHeight', { value: 600 });
    Object.defineProperty(element, 'scrollHeight', { value: 2400 });
    element.getBoundingClientRect = () => ({ top: 56 }) as DOMRect;
    element.style.scrollPaddingTop = '44px';
    const host = new ElementScrollHost(element, inZone);
    expect(host.clientHeight).toBe(600);
    expect(host.scrollHeight).toBe(2400);
    expect(host.viewportTop()).toBe(56);
    expect(host.scrollPaddingTop()).toBe(44);
  });

  it('converts a viewport y to a content offset', () => {
    const element = document.createElement('div');
    trackScrollTop(element).top = 300;
    element.getBoundingClientRect = () => ({ top: 56 }) as DOMRect;
    expect(new ElementScrollHost(element, inZone).toContentY(100)).toBe(344);
  });

  it('delegates scrollTo to the element at call time', () => {
    const element = document.createElement('div');
    const host = new ElementScrollHost(element, inZone);
    const scrollTo = jest.fn();
    element.scrollTo = scrollTo as unknown as typeof element.scrollTo;
    host.scrollTo({ top: 0, behavior: 'auto' });
    expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
  });

  it('listens to element scroll events until removed', () => {
    const element = document.createElement('div');
    const listener = jest.fn();
    const stop = new ElementScrollHost(element, inZone).listen(listener);
    element.dispatchEvent(new Event('scroll'));
    stop();
    element.dispatchEvent(new Event('scroll'));
    expect(listener).toHaveBeenCalledTimes(1);
  });

  it('watches the element size until removed', () => {
    installMockResizeObserver();
    const element = document.createElement('div');
    const listener = jest.fn();
    const stop = new ElementScrollHost(element, inZone).watchResize(listener);
    const observer = MockResizeObserver.instances.at(-1)!;
    expect(observer.targets.has(element)).toBe(true);
    observer.fire();
    stop();
    expect(listener).toHaveBeenCalledTimes(1);
    expect(observer.targets.size).toBe(0);
  });
});

describe('ScrollHosts', () => {
  it('registers scroll listeners outside the Angular zone (#501)', () => {
    const zone = TestBed.inject(NgZone);
    const element = document.createElement('div');
    const zones: boolean[] = [];
    zone.run(() =>
      TestBed.inject(ScrollHosts)
        .forElement(element)
        .listen(() => zones.push(NgZone.isInAngularZone())),
    );
    element.dispatchEvent(new Event('scroll'));
    expect(zones).toEqual([false]);
  });

  it('constructs its resize observer outside the Angular zone', () => {
    installMockResizeObserver();
    const zones: boolean[] = [];
    const Recording = class extends MockResizeObserver {
      constructor(callback: ResizeObserverCallback) {
        super(callback);
        zones.push(NgZone.isInAngularZone());
      }
    };
    (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = Recording;
    const zone = TestBed.inject(NgZone);
    zone.run(() =>
      TestBed.inject(ScrollHosts).forElement(document.createElement('div')).watchResize(jest.fn()),
    );
    expect(zones).toEqual([false]);
  });
});
