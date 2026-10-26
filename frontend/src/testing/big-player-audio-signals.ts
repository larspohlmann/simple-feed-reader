import { signal } from '@angular/core';
import { AudioTrack } from '../app/reader/audio-player.service';
import type { ChapterProgress } from '../app/reader/chapters/current-chapter';
import type { ChapterDto } from '../app/reader/models';

/** Every player signal the big player reads, for its own spec and AudioSurface's. */
export function bigPlayerAudioSignals() {
  return {
    current: signal<AudioTrack | null>(null),
    tracks: signal<AudioTrack[]>([]),
    index: signal(-1),
    hasNext: signal(false),
    playing: signal(false),
    position: signal(0),
    duration: signal(0),
    buffered: signal(0),
    chapters: signal<ChapterDto[]>([]),
    chapterIndex: signal(-1),
    currentChapter: signal<ChapterDto | null>(null),
    chapterProgress: signal<ChapterProgress | null>(null),
    hasPreviousChapter: signal(false),
    hasNextChapter: signal(false),
    rate: signal(1),
  };
}
