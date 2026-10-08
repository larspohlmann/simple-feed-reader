import { TestBed } from '@angular/core/testing';
import { PlaybackRate } from './playback-rate';

describe('PlaybackRate', () => {
  beforeEach(() => localStorage.clear());

  it('starts at normal speed', () => {
    expect(TestBed.inject(PlaybackRate).value()).toBe(1);
  });

  it('cycles 1, 1.25, 1.5, 2 and wraps back to 1', () => {
    const rate = TestBed.inject(PlaybackRate);

    expect([rate.cycle(), rate.cycle(), rate.cycle(), rate.cycle()]).toEqual([1.25, 1.5, 2, 1]);
    expect(rate.value()).toBe(1);
  });

  it('is read back by a new instance', () => {
    TestBed.inject(PlaybackRate).cycle();
    TestBed.resetTestingModule();

    expect(TestBed.inject(PlaybackRate).value()).toBe(1.25);
  });

  it.each(['3', 'abc'])('falls back to 1 for a stored %s', (stored) => {
    localStorage.setItem('sfr.audio.rate', stored);

    expect(TestBed.inject(PlaybackRate).value()).toBe(1);
  });
});
