import { DestroyRef } from '@angular/core';
import { PlaylistStore, SavedPlaylist } from './playlist.store';

const SAVE_INTERVAL_MS = 5000;

/** When the playlist reaches its store: at once on a queue change, throttled during playback
 *  and as the page hides; an empty snapshot clears it (#1429). */
export class PlaylistPersistence {
  private lastSaveAt = 0;

  constructor(
    private readonly store: PlaylistStore,
    private readonly snapshot: () => SavedPlaylist | null,
  ) {}

  load(): SavedPlaylist | null {
    return this.store.load();
  }

  save(): void {
    const saved = this.snapshot();
    if (!saved) return this.store.clear();
    this.store.save(saved);
  }

  saveThrottled(): void {
    if (Date.now() - this.lastSaveAt < SAVE_INTERVAL_MS) return;
    this.lastSaveAt = Date.now();
    this.save();
  }

  saveOnPageHide(destroyRef: DestroyRef): void {
    const save = (): void => this.save();
    window.addEventListener('pagehide', save);
    destroyRef.onDestroy(() => window.removeEventListener('pagehide', save));
  }

  clear(): void {
    this.store.clear();
  }
}
