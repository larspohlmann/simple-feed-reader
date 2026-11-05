import { NgZone } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { observeResize } from './observe-resize';
import {
  MockResizeObserver,
  installMockResizeObserver,
} from '../../testing/resize-observer-testing';

describe('observeResize', () => {
  const originalResizeObserver = globalThis.ResizeObserver;

  afterEach(() => {
    globalThis.ResizeObserver = originalResizeObserver;
  });

  it('observes every target and calls back until removed', () => {
    installMockResizeObserver();
    const first = document.createElement('div');
    const second = document.createElement('div');
    const callback = jest.fn();
    const stop = observeResize(TestBed.inject(NgZone), [first, second], callback);
    const observer = MockResizeObserver.instances.at(-1)!;
    expect([...observer.targets]).toEqual([first, second]);

    observer.fire();
    stop();

    expect(callback).toHaveBeenCalledTimes(1);
    expect(callback).toHaveBeenCalledWith();
    expect(observer.targets.size).toBe(0);
  });

  it('constructs its observer outside the Angular zone', () => {
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

    zone.run(() => observeResize(zone, [document.createElement('div')], jest.fn()));

    expect(zones).toEqual([false]);
  });

  it('is a no-op where the browser has no ResizeObserver', () => {
    (globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = undefined;
    const stop = observeResize(TestBed.inject(NgZone), [document.createElement('div')], jest.fn());
    expect(stop).not.toThrow();
  });
});
