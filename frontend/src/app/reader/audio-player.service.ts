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
import { AudioDeck } from './audio/audio-deck';
import { MediaSessionControls } from './audio/media-session-controls';

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
 * The reader's single audio player. It owns its HTMLAudioElements in code, never
 * placed in a template, so playback keeps running while the reader
 * view unmounts and across route changes; the mini-player bar is only a
 * reflection of these signals. It plays a playlist (#1429): the queue rules live
 * in `Playlist`, this service only drives the element from it. A second, standby
 * element caches the next track and swaps in when playback reaches it. The playlist and
 * position are saved through PlaylistStore and rehydrated paused on reload, and
 * cleared when the identity changes so one account's podcasts never bleed into
 * the next session.
 */
@Injectable({ providedIn: 'root' })
export class AudioPlayerService {
  private readonly deck = new AudioDeck(inject(AUDIO_ELEMENT_FACTORY), (element, isPlaying) =>
    this.bindElement(element, isPlaying),
  );
  private readonly destroyRef = inject(DestroyRef);
  private readonly store = inject(PlaylistStore);
  private readonly mediaSession = new MediaSessionControls();

  private readonly _playlist = signal<Playlist>(EMPTY_PLAYLIST);
  private readonly _playing = signal(false);
  private readonly _position = signal(0);
  private readonly _duration = signal(0);
  private readonly _buffered = signal(0);

  readonly tracks = computed(() => this._playlist().tracks);
  readonly index = computed(() => this._playlist().index);
  readonly current = computed(() => currentTrack(this._playlist()));
  readonly hasPrevious = computed(() => this.index() > 0);
  readonly hasNext = computed(() => this.index() < this.tracks().length - 1);
  readonly playing = this._playing.asReadonly();
  readonly position = this._position.asReadonly();
  readonly duration = this._duration.asReadonly();
  /** How far ahead of the playhead the stream is cached, in seconds from the start. */
  readonly buffered = this._buffered.asReadonly();

  private lastPersistAt = 0;
  private pendingSeek: number | null = null;

  constructor() {
    this.mediaSession.bind(
      {
        play: () => this.resume(),
        pause: () => this.element.pause(),
        skip: (seconds) => this.skip(seconds),
        seek: (seconds) => this.seek(seconds),
      },
      SKIP_SECONDS,
    );
    this.restore();
    this.bindLifecycle();
    effect(() =>
      this.mediaSession.setTrackSteps(
        this.hasPrevious() ? () => this.previous() : null,
        this.hasNext() ? () => this.next() : null,
      ),
    );
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
    this.deck.release();
    this._playlist.set(EMPTY_PLAYLIST);
    this._playing.set(false);
    this._position.set(0);
    this._duration.set(0);
    this._buffered.set(0);
    this.forget();
    this.mediaSession.showTrack(null);
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
    this.precacheNext();
  }

  private load(track: AudioTrack): void {
    this.pendingSeek = null;
    this._duration.set(track.durationInSeconds ?? 0);
    this._position.set(0);
    this._buffered.set(0);
    this.mediaSession.showTrack(track);
    const cached = this.deck.load(track.url);
    this.element.currentTime = 0;
    if (cached) this.adoptCachedElement();
  }

  /** The element that took over fired its load events while on standby; read them now. */
  private adoptCachedElement(): void {
    this.onPlaying(false);
    this.onMetadata();
    this.onProgress();
  }

  private precacheNext(): void {
    const next = this.tracks()[this.index() + 1];
    if (next) this.deck.cacheNearTheEnd(next.url, this._duration(), this._buffered());
  }

  private resume(): void {
    if (!this.current()) return;
    if (this.sourceIsDead()) return this.skipDeadTrack();
    if (this.element.error) this.reloadAtPosition();
    void this.element.play().catch(() => {
      /* Autoplay can be blocked until the user gestures; the play control retries. */
    });
  }

  /** Both elements are bound once; only the one playing is listened to. */
  private bindElement(element: HTMLAudioElement, isPlaying: () => boolean): void {
    const whenActive = (handler: () => void) => () => {
      if (isPlaying()) handler();
    };
    element.addEventListener(
      'progress',
      whenActive(() => this.onProgress()),
    );
    element.addEventListener(
      'timeupdate',
      whenActive(() => this.onTimeUpdate()),
    );
    element.addEventListener(
      'loadedmetadata',
      whenActive(() => this.onMetadata()),
    );
    element.addEventListener(
      'durationchange',
      whenActive(() => this.onMetadata()),
    );
    element.addEventListener(
      'play',
      whenActive(() => this.onPlaying(true)),
    );
    element.addEventListener(
      'pause',
      whenActive(() => this.onPlaying(false)),
    );
    element.addEventListener(
      'ended',
      whenActive(() => this.onEnded()),
    );
    element.addEventListener(
      'error',
      whenActive(() => this.onError()),
    );
  }

  private onTimeUpdate(): void {
    this._position.set(this.element.currentTime);
    this.onProgress();
    this.persistThrottled();
    this.mediaSession.showPosition(this._position(), this._duration());
  }

  /** The end of the cached range the playhead sits in; a range elsewhere (after a seek) is not ahead of it. */
  private onProgress(): void {
    const ranges = this.element.buffered;
    const playhead = this.element.currentTime;
    let end = playhead;
    for (let index = 0; index < ranges.length; index++) {
      if (ranges.start(index) <= playhead && playhead <= ranges.end(index)) end = ranges.end(index);
    }
    this._buffered.set(end);
    this.precacheNext();
  }

  private onMetadata(): void {
    if (Number.isFinite(this.element.duration) && this.element.duration > 0) {
      this._duration.set(this.element.duration);
    }
    if (this.pendingSeek !== null) {
      this.seek(this.pendingSeek);
      this.pendingSeek = null;
    }
    this.precacheNext();
  }

  private onPlaying(playing: boolean): void {
    this._playing.set(playing);
    if (!playing) this.persist();
    this.mediaSession.showPlaying(this._playing());
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

  private get element(): HTMLAudioElement {
    return this.deck.element;
  }
}
