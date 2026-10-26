import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { EmbedMedia, embedMediaOfFrame } from '../../audio/embed-media';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { SoundCloudChapterPlayer } from '../../chapters/soundcloud-chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { ChapterList, ChapterListPlace, addChapterList } from '../decorators/reader-chapters';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';
const CHAPTER_FRAMES = `${API_PLAYER}, iframe[src^="https://w.soundcloud.com/player/"]`;

/** The video player frame a body's chapter list drives when no episode owns the chapters. */
export function apiPlayerFrame(host: HTMLElement): HTMLIFrameElement | null {
  return host.querySelector<HTMLIFrameElement>(API_PLAYER);
}

/** Every embed a chapter list can drive: YouTube API frames and SoundCloud widgets, in document order. */
export function chapterFrames(host: HTMLElement): HTMLIFrameElement[] {
  return Array.from(host.querySelectorAll<HTMLIFrameElement>(CHAPTER_FRAMES));
}

interface ListToShow {
  readonly place: ChapterListPlace;
  readonly list: ChapterList;
  readonly player: ChapterPlayer;
  readonly mediaUrl: string | null;
  readonly frame: HTMLIFrameElement | null;
}

interface ShownList extends ListToShow {
  readonly element: HTMLElement | null;
}

/** A rendered body's chapter lists: its own, on the episode or the first API video (#1808), and one per embed (#1824). */
export class ArticleChapters {
  private shown: ShownList[] = [];

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, list: ChapterList, track: AudioTrack | null): void {
    this.release();
    if (list.chapters.length === 0) return;
    if (track) {
      const place = { host, after: null };
      this.show({ place, list, player: this.audioPlayer(track), mediaUrl: null, frame: null });
      return;
    }
    const frame = apiPlayerFrame(host);
    if (!frame) return;
    const place = { host, after: embedBoxOf(frame) };
    const mediaUrl = embedMediaOfFrame(frame)?.url ?? null;
    this.show({ place, list, player: new YouTubeChapterPlayer(frame), mediaUrl, frame });
  }

  attachToFrame(frame: HTMLIFrameElement, list: ChapterList): void {
    const media = embedMediaOfFrame(frame);
    const after = embedBoxOf(frame) ?? frame;
    const host = after.parentElement;
    if (!media || !host || list.chapters.length === 0 || this.hasList(frame)) return;
    const player = this.embedPlayer(frame, media);
    this.show({ place: { host, after }, list, player, mediaUrl: media.url, frame });
  }

  /** Once an embed is the audio player's track, its list seeks the player, not the paused frame (#1815). */
  followTrack(track: AudioTrack): void {
    const shown = this.shown.find((each) => each.mediaUrl === track.url);
    if (!shown) return;
    shown.player.release();
    shown.element?.remove();
    this.shown = this.shown.filter((each) => each !== shown);
    this.show({ ...shown, player: this.audioPlayer(track) });
  }

  release(): void {
    for (const shown of this.shown) {
      shown.player.release();
      shown.element?.remove();
    }
    this.shown = [];
  }

  private show(toShow: ListToShow): void {
    const { place, list, player } = toShow;
    this.shown.push({ ...toShow, element: addChapterList(place, list, player) });
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

function embedBoxOf(frame: HTMLIFrameElement): Element | null {
  return frame.closest('.reader-cinema') ?? frame.closest('.reader-embed');
}
