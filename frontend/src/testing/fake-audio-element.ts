import type { PlaybackElement } from '../app/reader/audio/playback/playback-element';
import { timeRanges } from './time-ranges-testing';

const SRC_NOT_SUPPORTED = 4;

/** An audio element the player drives; `fire` plays back what the real one would emit. */
export class FakeAudio implements PlaybackElement {
  error: Pick<MediaError, 'code' | 'MEDIA_ERR_SRC_NOT_SUPPORTED'> | null = null;
  currentTime = 0;
  readyState = 0;
  defaultPlaybackRate = 1;
  playbackRate = 1;
  preload: PlaybackElement['preload'] = '';
  buffered: TimeRanges = timeRanges();
  load = jest.fn();
  removeAttribute = jest.fn((name: string) => {
    if (name === 'src') this.source = '';
  });
  private source = '';

  /** Like the real element, a new source clears the last error. */
  get src(): string {
    return this.source;
  }

  set src(url: string) {
    this.source = url;
    this.error = null;
    this.playbackRate = this.defaultPlaybackRate;
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
