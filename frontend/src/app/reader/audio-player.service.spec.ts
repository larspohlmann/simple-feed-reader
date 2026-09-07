import { TestBed } from '@angular/core/testing';
import { AUDIO_ELEMENT_FACTORY, AudioPlayerService, AudioTrack } from './audio-player.service';
import { TokenStore } from '../core/token.store';

class FakeAudio {
  src = '';
  currentTime = 0;
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

  addEventListener(type: string, cb: () => void): void {
    (this.listeners[type] ??= []).push(cb);
  }

  removeEventListener(): void {
    /* The service never detaches; the stub has nothing to remove. */
  }

  fire(type: string): void {
    for (const cb of this.listeners[type] ?? []) cb();
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
});
