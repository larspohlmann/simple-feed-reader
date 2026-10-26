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
  withChapters,
} from './audio/playlist';
import { PlaylistStore, SavedPlaylist } from './audio/playlist.store';
import { PlaylistPersistence } from './audio/playlist-persistence';
import { AudioDeck } from './audio/audio-deck';
import { cachedEnd } from './audio/cached-end';
import { AudioTrack } from './audio/audio-track';
import { MediaSessionControls } from './audio/media-session-controls';
import { PlaybackRate } from './audio/playback-rate';
import { TrackChapters } from './audio/track-chapters';
import { PlaybackPosition } from './chapters/chapter-player';
import type { ChapterDto } from './models';

export type { AudioTrack, AudioTrackEntry } from './audio/audio-track';

/** Injected so tests supply a stub: jsdom does not implement HTMLMediaElement. */
export const AUDIO_ELEMENT_FACTORY = new InjectionToken<() => HTMLAudioElement>(
  'AUDIO_ELEMENT_FACTORY',
  { providedIn: 'root', factory: () => () => new Audio() },
);

/** Jump size for the skip controls and the OS seek keys (#915). */
export const SKIP_SECONDS = 15;
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
  private readonly deck = new AudioDeck(inject(AUDIO_ELEMENT_FACTORY), {
    progress: () => this.onProgress(),
    timeupdate: () => this.onTimeUpdate(),
    loadedmetadata: () => this.onMetadata(),
    durationchange: () => this.onMetadata(),
    play: () => this.onPlaying(true),
    pause: () => this.onPlaying(false),
    ended: () => this.onEnded(),
    error: () => this.onError(),
  });
  private readonly persistence = new PlaylistPersistence(inject(PlaylistStore), () =>
    this.snapshot(),
  );
  private readonly playbackRate = inject(PlaybackRate);
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
  readonly rate = this.playbackRate.value;

  readonly chapters = computed(() => this.current()?.chapters ?? []);
  private readonly playbackPosition = computed<PlaybackPosition>(() => ({
    seconds: this._position(),
    durationSeconds: this._duration() > 0 ? this._duration() : null,
  }));
  private readonly trackChapters = new TrackChapters(
    this.chapters,
    this.playbackPosition,
    RESTART_THRESHOLD_SECONDS,
  );
  readonly chapterIndex = this.trackChapters.index;
  readonly currentChapter = this.trackChapters.current;
  readonly chapterProgress = this.trackChapters.progress;
  readonly hasPreviousChapter = this.trackChapters.hasPrevious;
  readonly hasNextChapter = this.trackChapters.hasNext;

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
    this.deck.setRate(this.rate());
    this.persistence.saveOnPageHide(inject(DestroyRef));
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

  /** Plays `track` from `seconds`, seeking once its metadata is known; another track loads first (#1808). */
  playFrom(track: AudioTrack, seconds: number): void {
    if (this.current()?.url !== track.url) this.apply(insertNext(this._playlist(), track));
    this.seekOnceLoaded(seconds);
    this.resume();
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

  previousChapter(): void {
    this.seekToChapterStart(this.trackChapters.previousStart());
  }

  nextChapter(): void {
    this.seekToChapterStart(this.trackChapters.nextStart());
  }

  /** Plays the current track from its chapter `index`, seeking once its metadata is known. */
  playChapter(index: number): void {
    const chapter = this.chapters()[index];
    if (!chapter) return;
    this.seekOnceLoaded(chapter.startSeconds);
    this.resume();
  }

  /** Plays the queued track at `trackIndex` from its chapter `chapterIndex`; another track loads first. */
  playTrackChapter(trackIndex: number, chapterIndex: number): void {
    if (trackIndex !== this.index()) this.apply(select(this._playlist(), trackIndex));
    this.playChapter(chapterIndex);
  }

  /** Gives the queued track at `url` its chapters, for a track queued before its article was opened. */
  describeChapters(url: string, chapters: ChapterDto[]): void {
    const described = withChapters(this._playlist(), url, chapters);
    if (described !== this._playlist()) this.apply(described);
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

  cycleRate(): void {
    this.deck.setRate(this.playbackRate.cycle());
  }

  stop(): void {
    this.element.pause();
    this.deck.release();
    this._playlist.set(EMPTY_PLAYLIST);
    this._playing.set(false);
    this._position.set(0);
    this._duration.set(0);
    this._buffered.set(0);
    this.persistence.clear();
    this.mediaSession.showTrack(null);
  }

  private seekToChapterStart(start: number | null): void {
    if (start !== null) this.seek(start);
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
    this.persistence.save();
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

  private onTimeUpdate(): void {
    this._position.set(this.element.currentTime);
    this.onProgress();
    this.persistence.saveThrottled();
    this.mediaSession.showPosition(this._position(), this._duration(), this.rate());
  }

  private onProgress(): void {
    this._buffered.set(cachedEnd(this.element.buffered, this.element.currentTime));
    this.precacheNext();
  }

  private onMetadata(): void {
    const duration = this.element.duration;
    if (Number.isFinite(duration) && duration > 0) this._duration.set(duration);
    if (this.pendingSeek !== null) this.seek(this.pendingSeek);
    this.precacheNext();
  }

  private onPlaying(playing: boolean): void {
    this._playing.set(playing);
    if (!playing) this.persistence.save();
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
    this.persistence.save();
  }

  private skipDeadTrack(): void {
    if (this.hasNext()) return this.next();
    this._playing.set(false);
  }

  private sourceIsDead(): boolean {
    const error = this.element.error;
    return !!error && error.code === error.MEDIA_ERR_SRC_NOT_SUPPORTED;
  }

  private seekOnceLoaded(seconds: number): void {
    if (this.element.readyState >= HTMLMediaElement.HAVE_METADATA) return this.seek(seconds);
    this.pendingSeek = seconds;
  }

  private reloadAtPosition(): void {
    this.pendingSeek ??= this._position();
    this.element.load();
  }

  private restore(): void {
    const saved = this.persistence.load();
    if (!saved) return;
    const track = currentTrack(saved.playlist);
    if (!track) return;
    this._playlist.set(saved.playlist);
    this.load(track);
    this._position.set(saved.position);
    this.pendingSeek = saved.position;
  }

  private snapshot(): SavedPlaylist | null {
    if (!this.current()) return null;
    return { playlist: this._playlist(), position: this._position() };
  }

  private get element(): HTMLAudioElement {
    return this.deck.element;
  }
}
