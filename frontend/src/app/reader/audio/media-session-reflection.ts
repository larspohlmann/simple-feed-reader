import { Signal, effect } from '@angular/core';
import type { AudioTrack } from './audio-track';
import { MediaSessionActions, MediaSessionControls } from './media-session-controls';
import type { ChapterDto } from '../models';

/** What the OS media controls show of the player. */
export interface ReflectedPlayer {
  current: Signal<AudioTrack | null>;
  chapterControls: { current: Signal<ChapterDto | null> };
  playing: Signal<boolean>;
  position: Signal<number>;
  duration: Signal<number>;
  rate: Signal<number>;
  hasPrevious: Signal<boolean>;
  hasNext: Signal<boolean>;
}

export interface TrackStepActions {
  previous(): void;
  next(): void;
}

/** Keeps the OS media controls (lock screen, headphones, media keys) in step with the player:
 *  the track and its chapter as metadata, the playing state, the position and the track steps.
 *  Call it in an injection context; the mirroring runs as effects. */
export function reflectInMediaSession(
  player: ReflectedPlayer,
  actions: MediaSessionActions & TrackStepActions,
  skipSeconds: number,
): void {
  const controls = new MediaSessionControls();
  controls.bind(actions, skipSeconds);
  effect(() =>
    controls.setTrackSteps(
      player.hasPrevious() ? () => actions.previous() : null,
      player.hasNext() ? () => actions.next() : null,
    ),
  );
  effect(() => controls.showTrack(player.current(), player.chapterControls.current()));
  effect(() => controls.showPlaying(player.playing()));
  effect(() => controls.showPosition(player.position(), player.duration(), player.rate()));
}
