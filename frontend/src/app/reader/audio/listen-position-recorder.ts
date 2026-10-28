import { DestroyRef } from '@angular/core';
import { ListenPositions, SavedPosition } from './listen-positions';

/** Only a track longer than this has its place remembered (#1866). */
export const LONG_TRACK_SECONDS = 15 * 60;
/** A place this close to either end is not worth offering. */
export const POSITION_MARGIN_SECONDS = 15;

const SAVE_INTERVAL_MS = 5000;

/** Remembers where a long track was left, and forgets it once the track is finished. */
export class ListenPositionRecorder {
  private lastSaveAt = 0;

  constructor(
    private readonly positions: ListenPositions,
    private readonly snapshot: () => SavedPosition | null,
  ) {}

  record(): void {
    const position = this.snapshot();
    if (!position || position.durationSeconds <= LONG_TRACK_SECONDS) return;
    if (position.durationSeconds - position.seconds < POSITION_MARGIN_SECONDS) {
      return this.positions.forget(position.url);
    }
    if (position.seconds >= POSITION_MARGIN_SECONDS) this.positions.remember(position);
  }

  forget(): void {
    const position = this.snapshot();
    if (position) this.positions.forget(position.url);
  }

  recordThrottled(): void {
    if (Date.now() - this.lastSaveAt < SAVE_INTERVAL_MS) return;
    this.lastSaveAt = Date.now();
    this.record();
  }

  recordOnPageHide(destroyRef: DestroyRef): void {
    const record = (): void => this.record();
    window.addEventListener('pagehide', record);
    destroyRef.onDestroy(() => window.removeEventListener('pagehide', record));
  }
}
