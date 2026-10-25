import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { YouTubeChapterPlayer } from '../../chapters/youtube-chapter-player';
import { ChapterList, addChapterList } from '../decorators/reader-chapters';

const API_PLAYER = 'iframe[src*="enablejsapi=1"]';

/** One rendered body's chapter list and the player it drives: the video's API player, else the episode (#1808). */
export class ArticleChapters {
  private player: ChapterPlayer | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, list: ChapterList, track: AudioTrack | null): void {
    this.release();
    if (list.chapters.length === 0) return;
    const frame = host.querySelector<HTMLIFrameElement>(API_PLAYER);
    this.player = this.playerFor(frame, track);
    if (!this.player) return;
    const after = frame
      ? (frame.closest('.reader-cinema') ?? frame.closest('.reader-embed'))
      : null;
    addChapterList({ host, after }, list, this.player);
  }

  release(): void {
    this.player?.release();
    this.player = null;
  }

  private playerFor(
    frame: HTMLIFrameElement | null,
    track: AudioTrack | null,
  ): ChapterPlayer | null {
    if (frame) return new YouTubeChapterPlayer(frame);
    return track ? new AudioChapterPlayer(this.audio, track, this.injector) : null;
  }
}
