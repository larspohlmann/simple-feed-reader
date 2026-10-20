import { TestBed } from '@angular/core/testing';
import { Signal, WritableSignal, signal } from '@angular/core';
import { heldFor } from './held-for';

describe('heldFor', () => {
  const DELAY_MS = 150;
  let source: WritableSignal<boolean>;
  let held: Signal<boolean>;

  beforeEach(() => {
    jest.useFakeTimers();
    source = signal(false);
    held = TestBed.runInInjectionContext(() => heldFor(source, DELAY_MS));
    TestBed.tick();
  });

  afterEach(() => jest.useRealTimers());

  function settle(value: boolean, afterMs: number): void {
    source.set(value);
    TestBed.tick();
    jest.advanceTimersByTime(afterMs);
  }

  it('is false while the source is false', () => {
    expect(held()).toBe(false);
  });

  it('stays false until the source has held true for the delay', () => {
    settle(true, DELAY_MS - 1);

    expect(held()).toBe(false);
  });

  it('turns true once the source has held true for the delay', () => {
    settle(true, DELAY_MS);

    expect(held()).toBe(true);
  });

  it('drops the moment the source drops', () => {
    settle(true, DELAY_MS);
    settle(false, 0);

    expect(held()).toBe(false);
  });

  it('forgets a run the source cut short', () => {
    settle(true, DELAY_MS / 2);
    settle(false, DELAY_MS);

    expect(held()).toBe(false);
  });

  it('keeps the clock running when a signal the source reads changes to the same verdict', () => {
    const unrelated = signal(0);
    source = signal(true);
    held = TestBed.runInInjectionContext(() =>
      heldFor(() => unrelated() >= 0 && source(), DELAY_MS),
    );
    TestBed.tick();
    jest.advanceTimersByTime(DELAY_MS / 2);

    unrelated.set(1);
    TestBed.tick();
    jest.advanceTimersByTime(DELAY_MS / 2);

    expect(held()).toBe(true);
  });
});
