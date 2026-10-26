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

/** One rendered body's chapter list and the player it drives: the episode, else the video's API player (#1808). */
export class ArticleChapters {
  private shown: {
    place: ChapterListPlace;
    list: ChapterList;
    player: ChapterPlayer;
    element: HTMLElement | null;
  } | null = null;

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
    const shown = this.shown;
    if (!shown) return;
    shown.player.release();
    this.show(shown.place, shown.list, this.audioPlayer(track));
  }

  release(): void {
    this.shown?.player.release();
    this.shown?.element?.remove();
    this.shown = null;
  }

  private show(place: ChapterListPlace, list: ChapterList, player: ChapterPlayer): void {
    this.shown = { place, list, player, element: addChapterList(place, list, player) };
  }

  private audioPlayer(track: AudioTrack): AudioChapterPlayer {
    return new AudioChapterPlayer(this.audio, track, this.injector);
  }
}
