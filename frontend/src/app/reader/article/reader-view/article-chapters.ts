import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { ChapterList, ChapterListPlace, addChapterList } from '../decorators/reader-chapters';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';

/** The video player frame a body's chapter list drives when no episode owns the chapters. */
export function apiPlayerFrame(host: HTMLElement): HTMLIFrameElement | null {
  return host.querySelector<HTMLIFrameElement>(API_PLAYER);
}

interface PlacedList {
  readonly place: ChapterListPlace;
  readonly list: ChapterList;
}

/** One rendered body's chapter list and the player it drives: the episode, else the video's API player (#1808). */
export class ArticleChapters {
  private player: ChapterPlayer | null = null;
  private listElement: HTMLElement | null = null;
  private placed: PlacedList | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, list: ChapterList, track: AudioTrack | null): void {
    this.release();
    if (list.chapters.length === 0) return;
    if (track) {
      this.show({ host, after: null }, list, this.audioPlayer(track));
      return;
    }
    const frame = apiPlayerFrame(host);
    if (!frame) return;
    const after = frame.closest('.reader-cinema') ?? frame.closest('.reader-embed');
    this.show({ host, after }, list, new YouTubeChapterPlayer(frame));
  }

  /** Once the video is the audio player's track, its list seeks the player, not the paused frame (#1815). */
  followTrack(track: AudioTrack): void {
    const placed = this.placed;
    if (!placed) return;
    this.player?.release();
    this.show(placed.place, placed.list, this.audioPlayer(track));
  }

  release(): void {
    this.player?.release();
    this.player = null;
    this.listElement?.remove();
    this.listElement = null;
    this.placed = null;
  }

  private show(place: ChapterListPlace, list: ChapterList, player: ChapterPlayer): void {
    this.player = player;
    this.placed = { place, list };
    this.listElement = addChapterList(place, list, player);
  }

  private audioPlayer(track: AudioTrack): AudioChapterPlayer {
    return new AudioChapterPlayer(this.audio, track, this.injector);
  }
}
