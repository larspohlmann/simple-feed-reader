import { Injector } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../../audio-player.service';
import { AudioChapterPlayer } from '../../chapters/audio-chapter-player';
import { ChapterPlayer } from '../../chapters/chapter-player';
import { TranscriptView, addTranscript } from '../decorators/reader-transcript';

/** A rendered body's transcript section and the episode player its timestamps drive. */
export class ArticleTranscript {
  private element: HTMLElement | null = null;
  private player: ChapterPlayer | null = null;

  constructor(
    private readonly audio: AudioPlayerService,
    private readonly injector: Injector,
  ) {}

  attach(host: HTMLElement, view: TranscriptView, track: AudioTrack | null): void {
    this.release();
    this.player = track ? new AudioChapterPlayer(this.audio, track, this.injector) : null;
    this.element = addTranscript(host, view, this.player);
  }

  release(): void {
    this.player?.release();
    this.element?.remove();
    this.player = null;
    this.element = null;
  }
}
