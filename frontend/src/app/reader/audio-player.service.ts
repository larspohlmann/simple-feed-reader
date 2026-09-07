import { InjectionToken, Injectable, effect, inject, signal } from '@angular/core';
import { TokenStore } from '../core/token.store';

/** Everything the player needs to render and resume a track without another API
 *  call — built by the caller from the entry and its audio attachment (#915). */
export interface AudioTrack {
  url: string;
  title: string;
  faviconUrl: string | null;
  imageUrl: string | null;
  /** As the feed declared it, so the scrubber renders before metadata loads. */
  durationInSeconds: number | null;
}

/** Injected so tests supply a stub: jsdom does not implement HTMLMediaElement. */
export const AUDIO_ELEMENT_FACTORY = new InjectionToken<() => HTMLAudioElement>(
  'AUDIO_ELEMENT_FACTORY',
  { providedIn: 'root', factory: () => () => new Audio() },
);

const KEY = 'sfr.audio';
/** Jump size for the skip controls and the OS seek keys (#915). */
export const SKIP_SECONDS = 15;
const PERSIST_INTERVAL_MS = 5000;

/**
 * The reader's single audio player. It owns one HTMLAudioElement created in
 * code, never placed in a template, so playback keeps running while the reader
 * view unmounts and across route changes; the mini-player bar is only a
 * reflection of these signals. Position and the current track are saved to
 * localStorage and rehydrated paused on reload, and cleared on logout so one
 * account's podcast never bleeds into the next session.
 */
@Injectable({ providedIn: 'root' })
export class AudioPlayerService {
  private readonly element = inject(AUDIO_ELEMENT_FACTORY)();
  private readonly tokens = inject(TokenStore);

  private readonly _current = signal<AudioTrack | null>(null);
  private readonly _playing = signal(false);
  private readonly _position = signal(0);
  private readonly _duration = signal(0);

  readonly current = this._current.asReadonly();
  readonly playing = this._playing.asReadonly();
  readonly position = this._position.asReadonly();
  readonly duration = this._duration.asReadonly();

  private lastPersistAt = 0;
  private pendingSeek: number | null = null;

  constructor() {
    this.bindElement();
    this.bindMediaSession();
    this.restore();
    this.resetOnLogout();
  }

  play(track: AudioTrack): void {
    if (this._current()?.url === track.url) return this.resume();
    this.load(track);
  }

  toggle(): void {
    if (!this._current()) return;
    if (this._playing()) this.element.pause();
    else this.resume();
  }

  seek(seconds: number): void {
    const clamped = Math.max(0, Math.min(this._duration(), seconds));
    this.element.currentTime = clamped;
    this._position.set(clamped);
  }

  skip(delta: number): void {
    this.seek(this._position() + delta);
  }

  stop(): void {
    this.element.pause();
    this._current.set(null);
    this._playing.set(false);
    this._position.set(0);
    this._duration.set(0);
    this.forget();
    this.clearMediaMetadata();
  }

  private load(track: AudioTrack): void {
    this.element.src = track.url;
    this.element.currentTime = 0;
    this._current.set(track);
    this._duration.set(track.durationInSeconds ?? 0);
    this._position.set(0);
    this.setMediaMetadata(track);
    this.resume();
  }

  private resume(): void {
    void this.element.play().catch(() => {
      /* Autoplay can be blocked until the user gestures; the play control retries. */
    });
  }

  private bindElement(): void {
    this.element.addEventListener('timeupdate', () => this.onTimeUpdate());
    this.element.addEventListener('loadedmetadata', () => this.onMetadata());
    this.element.addEventListener('durationchange', () => this.onMetadata());
    this.element.addEventListener('play', () => this.onPlaying(true));
    this.element.addEventListener('pause', () => this.onPlaying(false));
    this.element.addEventListener('ended', () => this._playing.set(false));
  }

  private onTimeUpdate(): void {
    this._position.set(this.element.currentTime);
    this.persistThrottled();
    this.updatePositionState();
  }

  private onMetadata(): void {
    if (Number.isFinite(this.element.duration) && this.element.duration > 0) {
      this._duration.set(this.element.duration);
    }
    if (this.pendingSeek !== null) {
      this.seek(this.pendingSeek);
      this.pendingSeek = null;
    }
  }

  private onPlaying(playing: boolean): void {
    this._playing.set(playing);
    if (!playing) this.persist();
    this.reflectPlaybackState(playing);
  }

  private restore(): void {
    const raw = localStorage.getItem(KEY);
    if (!raw) return;
    try {
      const saved = JSON.parse(raw) as { track: AudioTrack; position: number };
      this._current.set(saved.track);
      this._duration.set(saved.track.durationInSeconds ?? 0);
      this._position.set(saved.position);
      this.element.src = saved.track.url;
      this.pendingSeek = saved.position;
    } catch {
      this.forget();
    }
  }

  private resetOnLogout(): void {
    let hadToken = this.tokens.token() !== null;
    effect(() => {
      const hasToken = this.tokens.token() !== null;
      if (hadToken && !hasToken) this.stop();
      hadToken = hasToken;
    });
  }

  private persistThrottled(): void {
    if (Date.now() - this.lastPersistAt < PERSIST_INTERVAL_MS) return;
    this.persist();
  }

  private persist(): void {
    const track = this._current();
    if (!track) return this.forget();
    localStorage.setItem(KEY, JSON.stringify({ track, position: this._position() }));
    this.lastPersistAt = Date.now();
  }

  private forget(): void {
    localStorage.removeItem(KEY);
  }

  private get session(): MediaSession | null {
    return 'mediaSession' in navigator ? navigator.mediaSession : null;
  }

  private bindMediaSession(): void {
    const session = this.session;
    if (!session) return;
    session.setActionHandler('play', () => this.resume());
    session.setActionHandler('pause', () => this.element.pause());
    session.setActionHandler('seekbackward', () => this.skip(-SKIP_SECONDS));
    session.setActionHandler('seekforward', () => this.skip(SKIP_SECONDS));
    session.setActionHandler('seekto', (details) => this.seekTo(details));
  }

  private seekTo(details: MediaSessionActionDetails): void {
    if (details.seekTime !== undefined && details.seekTime !== null) this.seek(details.seekTime);
  }

  private setMediaMetadata(track: AudioTrack): void {
    if (!this.session || !('MediaMetadata' in window)) return;
    const artwork = track.imageUrl ? [{ src: track.imageUrl }] : [];
    this.session.metadata = new MediaMetadata({ title: track.title, artwork });
  }

  private clearMediaMetadata(): void {
    if (this.session) this.session.metadata = null;
  }

  private reflectPlaybackState(playing: boolean): void {
    if (this.session) this.session.playbackState = playing ? 'playing' : 'paused';
  }

  private updatePositionState(): void {
    const duration = this._duration();
    if (!this.session?.setPositionState || duration <= 0) return;
    this.session.setPositionState({ duration, position: Math.min(this._position(), duration) });
  }
}
