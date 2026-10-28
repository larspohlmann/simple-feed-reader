import { PlaylistStore, SavedPlaylist } from './playlist.store';

/** When the playlist reaches its store; an empty snapshot clears it (#1429). */
export class PlaylistPersistence {
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
}
