import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import type { AudioTrack } from '../audio-player.service';
import type { ChapterDto } from '../models';
import { reflectInMediaSession } from './media-session-reflection';

class FakeMediaMetadata {
  constructor(readonly init: MediaMetadataInit) {}
}

type ActionHandler = ((details: { seekTime?: number }) => void) | null;

const track: AudioTrack = {
  url: 'https://x.test/ep.mp3',
  title: 'Episode 1',
  faviconUrl: null,
  imageUrl: null,
  durationInSeconds: 300,
};

function setUp() {
  const player = {
    current: signal<AudioTrack | null>(null),
    currentChapter: signal<ChapterDto | null>(null),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    rate: signal(1),
    hasPrevious: signal(false),
    hasNext: signal(false),
  };
  const actions = {
    play: jest.fn(),
    pause: jest.fn(),
    skip: jest.fn(),
    seek: jest.fn(),
    previous: jest.fn(),
    next: jest.fn(),
  };
  TestBed.runInInjectionContext(() => reflectInMediaSession(player, actions, 15));
  TestBed.tick();
  return { player, actions };
}

describe('reflectInMediaSession', () => {
  const handlers: Record<string, ActionHandler> = {};
  const session: {
    metadata: FakeMediaMetadata | null;
    playbackState: string;
    setPositionState: jest.Mock;
  } = { metadata: null, playbackState: 'none', setPositionState: jest.fn() };

  beforeEach(() => {
    session.metadata = null;
    session.playbackState = 'none';
    session.setPositionState.mockClear();
    Object.defineProperty(navigator, 'mediaSession', {
      configurable: true,
      value: Object.assign(session, {
        setActionHandler: (action: string, handler: ActionHandler) => (handlers[action] = handler),
      }),
    });
    Object.defineProperty(window, 'MediaMetadata', {
      configurable: true,
      value: FakeMediaMetadata,
    });
  });

  afterEach(() => {
    Reflect.deleteProperty(navigator, 'mediaSession');
    Reflect.deleteProperty(window, 'MediaMetadata');
  });

  it('routes the OS controls to the player', () => {
    const { actions } = setUp();

    handlers['play']?.({});
    handlers['pause']?.({});
    handlers['seekforward']?.({});
    handlers['seekbackward']?.({});
    handlers['seekto']?.({ seekTime: 42 });

    expect(actions.play).toHaveBeenCalled();
    expect(actions.pause).toHaveBeenCalled();
    expect(actions.skip).toHaveBeenNthCalledWith(1, 15);
    expect(actions.skip).toHaveBeenNthCalledWith(2, -15);
    expect(actions.seek).toHaveBeenCalledWith(42);
  });

  it('offers the OS track steps only while there is a track to step to', () => {
    const { player, actions } = setUp();
    expect(handlers['previoustrack']).toBeNull();
    expect(handlers['nexttrack']).toBeNull();

    player.hasNext.set(true);
    TestBed.tick();
    handlers['nexttrack']?.({});

    expect(actions.next).toHaveBeenCalled();
    expect(handlers['previoustrack']).toBeNull();
  });

  it('titles the metadata after the track and its chapter, and clears it without a track', () => {
    const { player } = setUp();
    expect(session.metadata).toBeNull();

    player.current.set(track);
    TestBed.tick();
    expect(session.metadata?.init.title).toBe('Episode 1');

    player.currentChapter.set({ startSeconds: 60, title: 'Main' });
    TestBed.tick();
    expect(session.metadata?.init).toMatchObject({ title: 'Main', artist: 'Episode 1' });

    player.current.set(null);
    player.currentChapter.set(null);
    TestBed.tick();
    expect(session.metadata).toBeNull();
  });

  it('mirrors the playing state', () => {
    const { player } = setUp();
    expect(session.playbackState).toBe('paused');

    player.playing.set(true);
    TestBed.tick();

    expect(session.playbackState).toBe('playing');
  });

  it('mirrors the position at the playback speed once the length is known', () => {
    const { player } = setUp();
    player.position.set(30);
    TestBed.tick();
    expect(session.setPositionState).not.toHaveBeenCalled();

    player.duration.set(300);
    player.rate.set(1.5);
    TestBed.tick();

    expect(session.setPositionState).toHaveBeenCalledWith({
      duration: 300,
      position: 30,
      playbackRate: 1.5,
    });
  });
});
