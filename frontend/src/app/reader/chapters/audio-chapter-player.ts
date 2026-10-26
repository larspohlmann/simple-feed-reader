import { EffectRef, Injector, effect } from '@angular/core';
import { AudioPlayerService, AudioTrack } from '../audio-player.service';
import { ChapterPlayer, PlaybackPosition } from './chapter-player';

/** Chapters of an episode, played through the app-wide audio player (#1808). */
export class AudioChapterPlayer implements ChapterPlayer {
  private watcher: EffectRef | null = null;

  constructor(
    private readonly player: AudioPlayerService,
    private readonly track: AudioTrack,
    private readonly injector: Injector,
  ) {}

  playFrom(seconds: number): void {
    this.player.playFrom(this.track, seconds);
  }

  watch(onPosition: (position: PlaybackPosition | null) => void): void {
    this.watcher?.destroy();
    this.watcher = effect(() => onPosition(this.position()), { injector: this.injector });
  }

  release(): void {
    this.watcher?.destroy();
    this.watcher = null;
  }

  private position(): PlaybackPosition | null {
    if (this.player.current()?.url !== this.track.url) return null;
    return this.player.playbackPosition();
  }
}
