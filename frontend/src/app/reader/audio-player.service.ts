import {
  DestroyRef,
  InjectionToken,
  Injectable,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { onIdentityChange } from '../core/auth/session-identity';
import {
  EMPTY_PLAYLIST,
  Playlist,
  append,
  currentTrack,
  indexOf,
  insertNext,
  move,
  remove,
  select,
} from './audio/playlist';
import { PlaylistStore } from './audio/playlist.store';

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

/** Jump size for the skip controls and the OS seek keys (#915). */
export const SKIP_SECONDS = 15;
const PERSIST_INTERVAL_MS = 5000;
/** Past this, previous restarts the current track instead of stepping back (#1429). */
export const RESTART_THRESHOLD_SECONDS = 3;

/**
 * The reader's single audio player. It owns one HTMLAudioElement created in
 * code, never placed in a template, so playback keeps running while the reader
 * view unmounts and across route changes; the mini-player bar is only a
 * reflection of these signals. It plays a playlist (#1429): the queue rules live
 * in `Playlist`, this service only drives the element from it. The playlist and
 * position are saved through PlaylistStore and rehydrated paused on reload, and
 * cleared when the identity changes so one account's podcasts never bleed into
 * the next session.
 */
@Injectable({ providedIn: 'root' })
export class AudioPlayerService {
  private readonly element = inject(AUDIO_ELEMENT_FACTORY)();
  private readonly destroyRef = inject(DestroyRef);
  private readonly store = inject(PlaylistStore);

  private readonly _playlist = signal<Playlist>(EMPTY_PLAYLIST);
  private readonly _playing = signal(false);
  private readonly _position = signal(0);
  private readonly _duration = signal(0);

  readonly tracks = computed(() => this._playlist().tracks);
  readonly index = computed(() => this._playlist().index);
  readonly current = computed(() => currentTrack(this._playlist()));
  readonly hasPrevious = computed(() => this.index() > 0);
  readonly hasNext = computed(() => this.index() < this.tracks().length - 1);
  readonly playing = this._playing.asReadonly();
  readonly position = this._position.asReadonly();
  readonly duration = this._duration.asReadonly();

  private lastPersistAt = 0;
  private pendingSeek: number | null = null;

  constructor() {
    this.bindElement();
    this.bindMediaSession();
    this.restore();
    this.bindLifecycle();
    this.bindTrackSteps();
    onIdentityChange(() => this.stop());
  }

  isQueued(url: string): boolean {
    return indexOf(this._playlist(), url) !== -1;
  }

  play(track: AudioTrack): void {
    if (this.current()?.url === track.url) return this.resume();
    this.go(insertNext(this._playlist(), track));
  }

  enqueue(track: AudioTrack): void {
    this.apply(append(this._playlist(), track));
  }

  dequeue(url: string): void {
    const wasPlaying = this._playing();
    this.apply(remove(this._playlist(), url));
    if (wasPlaying) this.resume();
  }

  playAt(index: number): void {
    if (index === this.index()) return this.resume();
    this.go(select(this._playlist(), index));
  }

  move(from: number, to: number): void {
    this.apply(move(this._playlist(), from, to));
  }

  next(): void {
    if (this.hasNext()) this.go(select(this._playlist(), this.index() + 1));
  }

  previous(): void {
    if (this._position() > RESTART_THRESHOLD_SECONDS || !this.hasPrevious()) return this.seek(0);
    this.go(select(this._playlist(), this.index() - 1));
  }

  toggle(): void {
    if (!this.current()) return;
    if (this._playing()) this.element.pause();
    else this.resume();
  }

  seek(seconds: number): void {
    const clamped = Math.max(0, Math.min(this._duration(), seconds));
    this.element.currentTime = clamped;
    this.pendingSeek = null;
    this._position.set(clamped);
  }

  skip(delta: number): void {
    this.seek(this._position() + delta);
  }

  stop(): void {
    this.element.pause();
    this._playlist.set(EMPTY_PLAYLIST);
    this._playing.set(false);
    this._position.set(0);
    this._duration.set(0);
    this.forget();
    this.clearMediaMetadata();
  }

  private go(playlist: Playlist): void {
    this.apply(playlist);
    this.resume();
  }

  /** Every queue change lands here: a new current track loads (paused), an empty queue stops. */
  private apply(playlist: Playlist): void {
    const track = currentTrack(playlist);
    if (!track) return this.stop();
    const changed = track.url !== this.current()?.url;
    this._playlist.set(playlist);
    if (changed) this.load(track);
    this.persist();
  }

  private load(track: AudioTrack): void {
    this.element.src = track.url;
    this.element.currentTime = 0;
    this.pendingSeek = null;
    this._duration.set(track.durationInSeconds ?? 0);
    this._position.set(0);
    this.setMediaMetadata(track);
  }

  private resume(): void {
    if (!this.current()) return;
    if (this.sourceIsDead()) return this.skipDeadTrack();
    if (this.element.error) this.reloadAtPosition();
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
    this.element.addEventListener('ended', () => this.onEnded());
    this.element.addEventListener('error', () => this.onError());
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
    this.reflectPlaybackState();
  }

  private onEnded(): void {
    if (this.hasNext()) return this.next();
    this._playing.set(false);
  }

  /** A dead enclosure (an expired podcast link) never ends, so it is skipped; a dropped
   *  connection only pauses, keeping the listener's place for play to reload from. */
  private onError(): void {
    if (this.sourceIsDead()) {
      if (this._playing()) this.skipDeadTrack();
      return;
    }
    this._playing.set(false);
    this.persist();
  }

  private skipDeadTrack(): void {
    if (this.hasNext()) return this.next();
    this._playing.set(false);
  }

  private sourceIsDead(): boolean {
    const error = this.element.error;
    return !!error && error.code === error.MEDIA_ERR_SRC_NOT_SUPPORTED;
  }

  private reloadAtPosition(): void {
    this.pendingSeek = this._position();
    this.element.load();
  }

  private restore(): void {
    const saved = this.store.load();
    if (!saved) return;
    const track = currentTrack(saved.playlist);
    if (!track) return;
    this._playlist.set(saved.playlist);
    this.load(track);
    this._position.set(saved.position);
    this.pendingSeek = saved.position;
  }

  private bindLifecycle(): void {
    const save = (): void => this.persist();
    window.addEventListener('pagehide', save);
    this.destroyRef.onDestroy(() => window.removeEventListener('pagehide', save));
  }

  private persistThrottled(): void {
    if (Date.now() - this.lastPersistAt < PERSIST_INTERVAL_MS) return;
    this.lastPersistAt = Date.now();
    this.persist();
  }

  private persist(): void {
    if (!this.current()) return this.forget();
    this.store.save({ playlist: this._playlist(), position: this._position() });
  }

  private forget(): void {
    this.store.clear();
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

  /** iOS swaps the lock screen's ±15 s buttons for track buttons once a track handler exists. */
  private bindTrackSteps(): void {
    const session = this.session;
    if (!session) return;
    effect(() => {
      session.setActionHandler('previoustrack', this.hasPrevious() ? () => this.previous() : null);
      session.setActionHandler('nexttrack', this.hasNext() ? () => this.next() : null);
    });
  }

  private seekTo(details: MediaSessionActionDetails): void {
    if (details.seekTime != null) this.seek(details.seekTime);
  }

  private setMediaMetadata(track: AudioTrack): void {
    if (!this.session || !('MediaMetadata' in window)) return;
    const artwork = track.imageUrl ? [{ src: track.imageUrl }] : [];
    this.session.metadata = new MediaMetadata({ title: track.title, artwork });
  }

  private clearMediaMetadata(): void {
    if (this.session) this.session.metadata = null;
  }

  private reflectPlaybackState(): void {
    if (this.session) this.session.playbackState = this._playing() ? 'playing' : 'paused';
  }

  private updatePositionState(): void {
    const duration = this._duration();
    if (!this.session?.setPositionState || duration <= 0) return;
    this.session.setPositionState({ duration, position: Math.min(this._position(), duration) });
  }
}
