import { TestBed } from '@angular/core/testing';
import {
  AUDIO_ELEMENT_FACTORY,
  AudioPlayerService,
  AudioTrack,
  RESTART_THRESHOLD_SECONDS,
} from './audio-player.service';
import { TokenStore } from '../core/auth/token.store';

const SRC_NOT_SUPPORTED = 4;
const NETWORK = 2;

function ranges(...spans: [number, number][]): TimeRanges {
  return {
    length: spans.length,
    start: (index: number) => spans[index][0],
    end: (index: number) => spans[index][1],
  };
}

class FakeAudio {
  error: { code: number; MEDIA_ERR_SRC_NOT_SUPPORTED: number } | null = null;
  currentTime = 0;
  preload = '';
  buffered: TimeRanges = ranges();
  load = jest.fn();
  private source = '';

  /** Like the real element, a new source clears the last error. */
  get src(): string {
    return this.source;
  }

  set src(url: string) {
    this.source = url;
    this.error = null;
  }

  fail(code: number): void {
    this.error = { code, MEDIA_ERR_SRC_NOT_SUPPORTED: SRC_NOT_SUPPORTED };
    this.fire('error');
  }

  duration = NaN;
  paused = true;
  play = jest.fn(() => {
    this.paused = false;
    this.fire('play');
    return Promise.resolve();
  });
  pause = jest.fn(() => {
    this.paused = true;
    this.fire('pause');
  });

  private readonly listeners: Record<string, (() => void)[]> = {};

  addEventListener(type: string, callback: () => void): void {
    (this.listeners[type] ??= []).push(callback);
  }

  removeEventListener(): void {
    /* The service never detaches; the stub has nothing to remove. */
  }

  fire(type: string): void {
    for (const callback of this.listeners[type] ?? []) callback();
  }
}

function track(overrides: Partial<AudioTrack> = {}): AudioTrack {
  return {
    url: 'https://x.test/ep.mp3',
    title: 'Episode 1',
    faviconUrl: null,
    imageUrl: null,
    durationInSeconds: 120,
    ...overrides,
  };
}

let audio: FakeAudio;

function make(): AudioPlayerService {
  audio = new FakeAudio();
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    providers: [
      { provide: AUDIO_ELEMENT_FACTORY, useValue: () => audio as unknown as HTMLAudioElement },
    ],
  });
  return TestBed.inject(AudioPlayerService);
}

describe('AudioPlayerService', () => {
  beforeEach(() => localStorage.clear());

  it('plays a track: sets it current, seeds the duration, starts the element', () => {
    const service = make();

    service.play(track({ durationInSeconds: 120 }));

    expect(service.current()?.url).toBe('https://x.test/ep.mp3');
    expect(service.duration()).toBe(120);
    expect(audio.play).toHaveBeenCalled();
    expect(service.playing()).toBe(true);
  });

  it('corrects the duration once the element reports its metadata', () => {
    const service = make();
    service.play(track({ durationInSeconds: 120 }));

    audio.duration = 118.5;
    audio.fire('loadedmetadata');

    expect(service.duration()).toBe(118.5);
  });

  it('follows the element position on time updates', () => {
    const service = make();
    service.play(track());

    audio.currentTime = 42;
    audio.fire('timeupdate');

    expect(service.position()).toBe(42);
  });

  it('toggles pause and resume from the current playing state', () => {
    const service = make();
    service.play(track());

    service.toggle();
    expect(audio.pause).toHaveBeenCalled();
    expect(service.playing()).toBe(false);

    service.toggle();
    expect(audio.play).toHaveBeenCalledTimes(2);
    expect(service.playing()).toBe(true);
  });

  it('clamps a seek to the track bounds', () => {
    const service = make();
    service.play(track({ durationInSeconds: 120 }));

    service.seek(999);
    expect(audio.currentTime).toBe(120);
    expect(service.position()).toBe(120);

    service.seek(-5);
    expect(audio.currentTime).toBe(0);
    expect(service.position()).toBe(0);
  });

  it('skips relative to the current position', () => {
    const service = make();
    service.play(track({ durationInSeconds: 120 }));
    audio.currentTime = 40;
    audio.fire('timeupdate');

    service.skip(15);

    expect(service.position()).toBe(55);
  });

  it('stops: clears the track and forgets the saved state', () => {
    const service = make();
    service.play(track());
    audio.currentTime = 30;
    audio.fire('timeupdate');

    service.stop();

    expect(service.current()).toBeNull();
    expect(service.playing()).toBe(false);
    expect(audio.pause).toHaveBeenCalled();
    expect(localStorage.getItem('sfr.audio')).toBeNull();
  });

  it('rehydrates the last track paused at its saved position on construct', () => {
    const first = make();
    first.play(track({ durationInSeconds: 120 }));
    audio.currentTime = 30;
    audio.fire('timeupdate');

    const restored = make();

    expect(restored.current()?.url).toBe('https://x.test/ep.mp3');
    expect(restored.position()).toBe(30);
    expect(restored.playing()).toBe(false);
    expect(audio.play).not.toHaveBeenCalled();
  });

  it('saves the latest position when the page is hidden, past the write throttle', () => {
    const service = make();
    service.play(track());
    audio.currentTime = 12;
    audio.fire('timeupdate');
    audio.currentTime = 18;
    audio.fire('timeupdate');

    window.dispatchEvent(new Event('pagehide'));

    expect(JSON.parse(localStorage.getItem('sfr.audio') ?? '{}').position).toBe(18);
  });

  it('stops and clears when the signed-in identity changes', () => {
    const service = make();
    const tokens = TestBed.inject(TokenStore);
    tokens.set('a-jwt');
    TestBed.tick();
    service.play(track());
    audio.currentTime = 30;
    audio.fire('timeupdate');

    tokens.clear();
    TestBed.tick();

    expect(service.current()).toBeNull();
    expect(localStorage.getItem('sfr.audio')).toBeNull();
  });

  describe('as a playlist', () => {
    const one = track({ url: 'https://x.test/1.mp3', title: 'One' });
    const two = track({ url: 'https://x.test/2.mp3', title: 'Two' });
    const three = track({ url: 'https://x.test/3.mp3', title: 'Three' });

    function titles(service: AudioPlayerService): string[] {
      return service.tracks().map((queued) => queued.title);
    }

    function at(seconds: number): void {
      audio.currentTime = seconds;
      audio.fire('timeupdate');
    }

    it('adds without interrupting what plays', () => {
      const service = make();
      service.play(one);
      audio.play.mockClear();

      service.enqueue(two);
      service.enqueue(three);

      expect(titles(service)).toEqual(['One', 'Two', 'Three']);
      expect(service.current()).toBe(one);
      expect(audio.src).toBe(one.url);
      expect(audio.play).not.toHaveBeenCalled();
      expect(service.isQueued(two.url)).toBe(true);
    });

    it('loads the first added track paused into an empty player', () => {
      const service = make();

      service.enqueue(one);

      expect(service.current()).toBe(one);
      expect(audio.src).toBe(one.url);
      expect(audio.play).not.toHaveBeenCalled();
      expect(service.playing()).toBe(false);
    });

    it('plays a listened track next to the current one and keeps the queue', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);

      service.play(three);

      expect(titles(service)).toEqual(['One', 'Three', 'Two']);
      expect(service.current()).toBe(three);
      expect(audio.src).toBe(three.url);
      expect(service.playing()).toBe(true);
    });

    it('advances to the next track when one ends, and stops after the last', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);

      audio.fire('ended');
      expect(service.current()).toBe(two);
      expect(audio.src).toBe(two.url);
      expect(service.playing()).toBe(true);

      audio.pause();
      audio.fire('ended');
      expect(service.current()).toBe(two);
      expect(service.playing()).toBe(false);
    });

    it('plays the reordered order', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);
      service.enqueue(three);

      service.move(2, 1);
      audio.fire('ended');

      expect(titles(service)).toEqual(['One', 'Three', 'Two']);
      expect(service.current()).toBe(three);
    });

    it('steps with next and previous', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);

      service.next();
      expect(service.current()).toBe(two);
      expect(service.hasNext()).toBe(false);

      service.previous();
      expect(service.current()).toBe(one);
      expect(service.hasPrevious()).toBe(false);
    });

    it('restarts the current track on previous once past the threshold', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);
      service.next();
      at(RESTART_THRESHOLD_SECONDS + 1);

      service.previous();

      expect(service.current()).toBe(two);
      expect(audio.currentTime).toBe(0);
    });

    it('restarts the first track on previous', () => {
      const service = make();
      service.play(one);
      at(1);

      service.previous();

      expect(service.current()).toBe(one);
      expect(audio.currentTime).toBe(0);
    });

    it('skips a dead track while playing', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);

      audio.fail(SRC_NOT_SUPPORTED);

      expect(service.current()).toBe(two);
      expect(audio.src).toBe(two.url);
    });

    it('stops on a dead last track and shows it paused', () => {
      const service = make();
      service.play(one);

      audio.fail(SRC_NOT_SUPPORTED);

      expect(service.current()).toBe(one);
      expect(service.playing()).toBe(false);
    });

    it('waits on a dead track loaded paused, and skips it once play is pressed', () => {
      const service = make();
      service.enqueue(one);
      service.enqueue(two);
      audio.fail(SRC_NOT_SUPPORTED);
      expect(service.current()).toBe(one);

      service.toggle();

      expect(service.current()).toBe(two);
      expect(audio.src).toBe(two.url);
      expect(service.playing()).toBe(true);
    });

    it('pauses on a dropped connection, keeping the place, and reloads it on play', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);
      at(600);

      audio.fail(NETWORK);

      expect(service.current()).toBe(one);
      expect(service.playing()).toBe(false);
      expect(JSON.parse(localStorage.getItem('sfr.audio') ?? '{}').position).toBe(600);

      audio.play.mockClear();
      service.toggle();
      expect(audio.load).toHaveBeenCalled();
      expect(audio.play).toHaveBeenCalled();
      audio.duration = 3600;
      audio.fire('loadedmetadata');
      expect(audio.currentTime).toBe(600);
    });

    it('plays a chosen row', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);

      service.playAt(1);

      expect(service.current()).toBe(two);
      expect(service.playing()).toBe(true);
    });

    it('hands a removed playing track over to the next one, still playing', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);
      audio.play.mockClear();

      service.dequeue(one.url);

      expect(audio.play).toHaveBeenCalled();

      expect(titles(service)).toEqual(['Two']);
      expect(audio.src).toBe(two.url);
      expect(service.playing()).toBe(true);
    });

    it('keeps a paused player paused when its track is removed', () => {
      const service = make();
      service.play(one);
      service.enqueue(two);
      service.toggle();
      audio.play.mockClear();

      service.dequeue(one.url);

      expect(service.current()).toBe(two);
      expect(audio.play).not.toHaveBeenCalled();
    });

    it('stops when the last track is removed', () => {
      const service = make();
      service.play(one);

      service.dequeue(one.url);

      expect(service.current()).toBeNull();
      expect(localStorage.getItem('sfr.audio')).toBeNull();
    });

    it('restores the playlist, its current track and position paused', () => {
      const first = make();
      first.play(one);
      first.enqueue(two);
      first.next();
      at(20);
      window.dispatchEvent(new Event('pagehide'));

      const restored = make();

      expect(titles(restored)).toEqual(['One', 'Two']);
      expect(restored.current()?.url).toBe(two.url);
      expect(restored.position()).toBe(20);
      expect(audio.play).not.toHaveBeenCalled();
    });

    it('restores the single track saved before playlists', () => {
      localStorage.setItem('sfr.audio', JSON.stringify({ track: one, position: 9 }));

      const restored = make();

      expect(titles(restored)).toEqual(['One']);
      expect(restored.position()).toBe(9);
    });

    it('binds the OS track controls only while there is a track to step to, and plays nothing after close', () => {
      const handlers: Record<string, (() => void) | null> = {};
      Object.defineProperty(navigator, 'mediaSession', {
        configurable: true,
        value: {
          setActionHandler: (action: string, handler: (() => void) | null) =>
            (handlers[action] = handler),
        },
      });
      try {
        const service = make();
        service.play(one);
        TestBed.tick();
        expect(handlers['nexttrack']).toBeNull();
        expect(handlers['previoustrack']).toBeNull();

        service.enqueue(two);
        TestBed.tick();
        handlers['nexttrack']?.();
        expect(service.current()).toBe(two);
        TestBed.tick();
        expect(handlers['nexttrack']).toBeNull();
        handlers['previoustrack']?.();
        expect(service.current()).toBe(one);

        service.stop();
        audio.play.mockClear();
        handlers['play']?.();
        expect(audio.play).not.toHaveBeenCalled();
      } finally {
        delete (navigator as { mediaSession?: unknown }).mediaSession;
      }
    });
  });

  describe('pre-caching', () => {
    it('asks the element to buffer ahead', () => {
      make();

      expect(audio.preload).toBe('auto');
    });

    it('reports the end of the cached range the playhead is in', () => {
      const service = make();
      service.play(track());
      audio.currentTime = 30;
      audio.buffered = ranges([0, 10], [20, 75], [90, 100]);

      audio.fire('progress');

      expect(service.buffered()).toBe(75);
    });

    it('reports nothing ahead when the playhead sits outside every cached range', () => {
      const service = make();
      service.play(track());
      audio.currentTime = 50;
      audio.buffered = ranges([0, 10]);

      audio.fire('progress');

      expect(service.buffered()).toBe(50);
    });

    it('starts a new track with nothing cached', () => {
      const service = make();
      service.play(track());
      audio.buffered = ranges([0, 80]);
      audio.fire('progress');

      service.play(track({ url: 'https://x.test/other.mp3' }));

      expect(service.buffered()).toBe(0);
    });
  });
});
