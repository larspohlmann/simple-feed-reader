import { computed, signal } from '@angular/core';
import { AudioTrack } from '../app/reader/audio-player.service';
import type { ChapterProgress } from '../app/reader/chapters/current-chapter';
import type { ChapterDto } from '../app/reader/models';

/** Every player signal the mini bar and the big player read, for their specs and AudioSurface's. */
export function bigPlayerAudioSignals() {
  const chapters = signal<ChapterDto[]>([]);
  const chapterIndex = signal(-1);
  const chapterProgress = signal<ChapterProgress | null>(null);
  return {
    current: signal<AudioTrack | null>(null),
    tracks: signal<AudioTrack[]>([]),
    index: signal(-1),
    hasNext: signal(false),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    buffered: signal(0),
    chapters,
    hasChapters: computed(() => chapters().length > 0),
    chapterIndex,
    currentChapter: signal<ChapterDto | null>(null),
    chapterProgress,
    chapterPlayhead: computed(() =>
      chapterIndex() === -1 ? null : { index: chapterIndex(), progress: chapterProgress() },
    ),
    hasPreviousChapter: signal(false),
    hasNextChapter: signal(false),
    rate: signal(1),
  };
}
