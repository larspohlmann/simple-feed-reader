import { DestroyRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { ListenPositions } from './listen-positions';
import { PlaybackMemory } from './playback-memory';
import { PlaylistStore } from './playlist.store';

const URL = 'https://x.test/ep.mp3';
const HOUR = 3600;

function setUp() {
  TestBed.configureTestingModule({ providers: [provideAccountIdentity()] });
  const positions = TestBed.inject(ListenPositions);
  const track = {
    url: URL,
    title: 'Episode',
    faviconUrl: null,
    imageUrl: null,
    durationInSeconds: null,
  };
  const state = { playlist: { tracks: [track], index: 0 }, position: 600, duration: HOUR };
  const memory = new PlaybackMemory(new PlaylistStore(), positions, () => state);
  const moveTo = (seconds: number): void => {
    state.position = seconds;
  };
  return { positions, memory, moveTo };
}

function savedPlaylistPosition(): number | null {
  const raw = localStorage.getItem('sfr.audio');
  return raw ? JSON.parse(raw).position : null;
}

describe('PlaybackMemory', () => {
  beforeEach(() => {
    localStorage.clear();
    jest.useFakeTimers();
  });

  afterEach(() => jest.useRealTimers());

  it('saves the playlist and the place at most every five seconds while throttled', () => {
    const { positions, memory, moveTo } = setUp();
    memory.saveThrottled();
    moveTo(601);
    memory.saveThrottled();

    expect(savedPlaylistPosition()).toBe(600);
    expect(positions.get(URL)?.seconds).toBe(600);

    jest.advanceTimersByTime(5000);
    memory.saveThrottled();

    expect(savedPlaylistPosition()).toBe(601);
    expect(positions.get(URL)?.seconds).toBe(601);
  });

  it('saves the playlist and the place as the page hides, until destroyed', () => {
    const { positions, memory, moveTo } = setUp();
    const onDestroy: (() => void)[] = [];
    memory.saveOnPageHide({
      onDestroy: (callback: () => void) => onDestroy.push(callback),
    } as unknown as DestroyRef);

    window.dispatchEvent(new Event('pagehide'));
    expect(savedPlaylistPosition()).toBe(600);
    expect(positions.get(URL)?.seconds).toBe(600);

    onDestroy.forEach((callback) => callback());
    moveTo(700);
    window.dispatchEvent(new Event('pagehide'));
    expect(savedPlaylistPosition()).toBe(600);
    expect(positions.get(URL)?.seconds).toBe(600);
  });
});
