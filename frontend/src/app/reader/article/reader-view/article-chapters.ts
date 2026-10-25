import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { ChapterList, addChapterList } from '../decorators/reader-chapters';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';

/** One rendered body's chapter list and the player it drives: the episode, else the video's API player (#1808). */
export class ArticleChapters {
  private player: ChapterPlayer | null = null;
  private list: HTMLElement | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, list: ChapterList, track: AudioTrack | null): void {
    this.release();
    if (list.chapters.length === 0) return;
    if (track) {
      this.player = new AudioChapterPlayer(this.audio, track, this.injector);
      this.list = addChapterList({ host, after: null }, list, this.player);
      return;
    }
    const frame = host.querySelector<HTMLIFrameElement>(API_PLAYER);
    if (!frame) return;
    this.player = new YouTubeChapterPlayer(frame);
    const after = frame.closest('.reader-cinema') ?? frame.closest('.reader-embed');
    this.list = addChapterList({ host, after }, list, this.player);
  }

  release(): void {
    this.player?.release();
    this.player = null;
    this.list?.remove();
    this.list = null;
  }
}
