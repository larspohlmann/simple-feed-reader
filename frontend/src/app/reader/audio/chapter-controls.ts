import { Signal } from '@angular/core';
import type { ChapterDto } from '../models';
import type { PlaybackPosition } from '../chapters/chapter-player';
import type { AudioTrack } from './audio-track';
import { TrackChapters } from './track-chapters';

/** What chapter navigation needs of the player. */
export interface ChapterPlayer {
  current(): AudioTrack | null;
  seek(seconds: number): void;
  playFrom(track: AudioTrack, seconds: number): void;
}

/** The track's chapters and the playhead they are followed from. */
export interface ChapterSource {
  chapters: Signal<readonly ChapterDto[]>;
  position: Signal<PlaybackPosition>;
  restartThreshold: number;
}

/** Follows the playing track's chapters and moves the player between them (#1810, #1814). */
export class ChapterControls {
  private readonly trackChapters: TrackChapters;
  readonly index: Signal<number>;
  readonly current: TrackChapters['current'];
  readonly progress: TrackChapters['progress'];
  readonly playhead: TrackChapters['playhead'];
  readonly hasPrevious: Signal<boolean>;
  readonly hasNext: Signal<boolean>;

  constructor(
    private readonly source: ChapterSource,
    private readonly player: ChapterPlayer,
  ) {
    this.trackChapters = new TrackChapters(
      source.chapters,
      source.position,
      source.restartThreshold,
    );
    this.index = this.trackChapters.index;
    this.current = this.trackChapters.current;
    this.progress = this.trackChapters.progress;
    this.playhead = this.trackChapters.playhead;
    this.hasPrevious = this.trackChapters.hasPrevious;
    this.hasNext = this.trackChapters.hasNext;
  }

  previous(): void {
    this.seekToStart(this.trackChapters.previousStart());
  }

  next(): void {
    this.seekToStart(this.trackChapters.nextStart());
  }

  play(index: number): void {
    const track = this.player.current();
    const chapter = this.source.chapters()[index];
    if (track && chapter) this.player.playFrom(track, chapter.startSeconds);
  }

  private seekToStart(start: number | null): void {
    if (start !== null) this.player.seek(start);
  }
}
