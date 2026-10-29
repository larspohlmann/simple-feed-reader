import { Injector, WritableSignal, inputBinding, signal } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { SoundCloudChapterPlayer } from '../../chapters/soundcloud-chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { SOUNDCLOUD_PLAYER_URL } from '../../media/soundcloud-widget-protocol';
import { ChapterDto } from '../../models';
import { EmbedChaptersComponent } from '../embed-chapters/embed-chapters.component';
import { ArticleIslands } from './article-islands';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';
const CHAPTER_FRAMES = `${API_PLAYER}, iframe[src^="${SOUNDCLOUD_PLAYER_URL}"]`;

/** The video player frame a body's chapter list drives. */
export function apiPlayerFrame(host: HTMLElement): HTMLIFrameElement | null {
  return host.querySelector<HTMLIFrameElement>(API_PLAYER);
}

/** Every embed a chapter list can drive: YouTube API frames and SoundCloud widgets, in document order. */
export function chapterFrames(host: HTMLElement): HTMLIFrameElement[] {
  return Array.from(host.querySelectorAll<HTMLIFrameElement>(CHAPTER_FRAMES));
}

interface ShownList {
  readonly frame: HTMLIFrameElement;
  readonly mediaUrl: string;
  readonly player: WritableSignal<ChapterPlayer>;
}

/** A rendered body's chapter lists: one under each embed (#1808, #1824, #1886). */
export class ArticleChapters {
  private shown: ShownList[] = [];

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly islands: ArticleIslands,
    private readonly injector: Injector,
  ) {}

  /** The first list attached to a frame stays its only one. */
  attachToFrame(frame: HTMLIFrameElement, chapters: readonly ChapterDto[]): void {
    const media = embedMediaOfFrame(frame);
    const after = frame.closest('.reader-cinema') ?? frame.closest('.reader-embed') ?? frame;
    if (!media || !after.parentElement || chapters.length === 0 || this.hasList(frame)) return;
    const player = signal(this.embedPlayer(frame, media));
    this.islands.mountAfter(after, EmbedChaptersComponent, [
      inputBinding('chapters', () => chapters),
      inputBinding('player', player),
    ]);
    this.shown.push({ frame, mediaUrl: media.url, player });
  }

  /** Once an embed is the audio player's track, its list seeks the player, not the paused frame (#1815). */
  followTrack(track: AudioTrack): void {
    for (const shown of this.shown.filter((each) => each.mediaUrl === track.url)) {
      shown.player.set(this.audioPlayer(track));
    }
  }

  forget(): void {
    this.shown = [];
  }

  private hasList(frame: HTMLIFrameElement): boolean {
    return this.shown.some((shown) => shown.frame === frame);
  }

  private embedPlayer(frame: HTMLIFrameElement, media: EmbedMedia): ChapterPlayer {
    const current = this.audio.current();
    if (current?.url === media.url) return this.audioPlayer(current);
    return media.kind === 'youtube'
      ? new YouTubeChapterPlayer(frame)
      : new SoundCloudChapterPlayer(frame);
  }

  private audioPlayer(track: AudioTrack): AudioChapterPlayer {
    return new AudioChapterPlayer(this.audio, track, this.injector);
  }
}
