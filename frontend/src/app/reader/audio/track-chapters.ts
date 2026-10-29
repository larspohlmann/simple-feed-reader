import { Signal, computed } from '@angular/core';
import { PlaybackPosition } from '../chapters/chapter-player';
import { ChapterProgress, chapterProgress, currentChapterIndex } from '../chapters/current-chapter';
import type { ChapterDto } from '../models';
import type { AudioTrack } from './audio-track';

/** Past this, previous restarts the current track or chapter instead of stepping back (#1429). */
export const RESTART_THRESHOLD_SECONDS = 3;

export interface ChapterNavigationPlayer {
  current(): AudioTrack | null;
  seek(seconds: number): void;
  playFrom(track: AudioTrack, seconds: number): void;
}

/** Where previous chapter seeks to, or null when there is nowhere to go: the current chapter's
 *  start once `position` is past it by more than `threshold` (like `previous()` on a track),
 *  else the start of the one before. */
export function previousChapterStart(
  starts: readonly number[],
  position: number,
  threshold: number,
): number | null {
  const index = currentChapterIndex(starts, position);
  if (index === -1) return null;
  if (position - starts[index] > threshold) return starts[index];
  return starts[index - 1] ?? null;
}

/** Where next chapter seeks to: the following chapter's start, or null in the last chapter. */
export function nextChapterStart(starts: readonly number[], position: number): number | null {
  return starts[currentChapterIndex(starts, position) + 1] ?? null;
}

/** The chapter playback is in and how far through it; null outside every chapter. */
export interface ChapterPlayhead {
  readonly index: number;
  readonly progress: ChapterProgress | null;
}

/** Where `position` is among the chapters starting at `starts`; null before the first. */
export function chapterPlayhead(
  starts: readonly number[],
  position: PlaybackPosition,
): ChapterPlayhead | null {
  const index = currentChapterIndex(starts, position.seconds);
  return index === -1 ? null : { index, progress: chapterProgress(starts, index, position) };
}

/** Follows the playing track's chapters and moves the player between them (#1810, #1814). */
export class TrackChapters {
  private readonly starts = computed(() => this.chapters().map((chapter) => chapter.startSeconds));
  /** -1 before the first chapter and without chapters. */
  readonly index = computed(() => currentChapterIndex(this.starts(), this.position().seconds));
  readonly current = computed(() => this.chapters()[this.index()] ?? null);
  /** Null without a chapter or while its end is unknown. */
  readonly progress = computed(() => {
    const index = this.index();
    return index === -1 ? null : chapterProgress(this.starts(), index, this.position());
  });
  readonly playhead = computed(() => chapterPlayhead(this.starts(), this.position()));
  readonly previousStart = computed(() =>
    previousChapterStart(this.starts(), this.position().seconds, RESTART_THRESHOLD_SECONDS),
  );
  readonly nextStart = computed(() => nextChapterStart(this.starts(), this.position().seconds));
  readonly hasPrevious = computed(() => this.previousStart() !== null);
  readonly hasNext = computed(() => this.nextStart() !== null);

  constructor(
    private readonly chapters: Signal<readonly ChapterDto[]>,
    private readonly position: Signal<PlaybackPosition>,
    private readonly player: ChapterNavigationPlayer,
  ) {}

  previous(): void {
    this.seekToStart(this.previousStart());
  }

  next(): void {
    this.seekToStart(this.nextStart());
  }

  play(index: number): void {
    const track = this.player.current();
    const chapter = this.chapters()[index];
    if (track && chapter) this.player.playFrom(track, chapter.startSeconds);
  }

  private seekToStart(start: number | null): void {
    if (start !== null) this.player.seek(start);
  }
}
