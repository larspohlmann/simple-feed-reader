import { DestroyRef } from '@angular/core';
import { ListenPositionRecorder } from './listen-position-recorder';
import { ListenPositions, type SavedPosition } from './listen-positions';
import { Playlist, currentTrack } from './playlist';
import { PlaylistPersistence } from './playlist-persistence';
import { PlaylistStore, type SavedPlaylist } from './playlist.store';

const SAVE_INTERVAL_MS = 5000;

interface PlayerState {
  playlist: Playlist;
  position: number;
  duration: number;
}

function playlistSnapshot(state: PlayerState): SavedPlaylist | null {
  if (!currentTrack(state.playlist)) return null;
  return { playlist: state.playlist, position: state.position };
}

function placeSnapshot(state: PlayerState): SavedPosition | null {
  const track = currentTrack(state.playlist);
  if (!track) return null;
  return { url: track.url, seconds: state.position, durationSeconds: state.duration };
}

/** What the player keeps of itself: the playlist across reloads, and where long tracks were left (#1866). */
export class PlaybackMemory {
  private readonly playlist: PlaylistPersistence;
  private readonly places: ListenPositionRecorder;
  private lastSaveAt = 0;

  constructor(store: PlaylistStore, positions: ListenPositions, state: () => PlayerState) {
    this.playlist = new PlaylistPersistence(store, () => playlistSnapshot(state()));
    this.places = new ListenPositionRecorder(positions, () => placeSnapshot(state()));
  }

  load(): SavedPlaylist | null {
    return this.playlist.load();
  }

  saveQueue(): void {
    this.playlist.save();
  }

  rememberPlace(): void {
    this.places.record();
  }

  forgetPlace(): void {
    this.places.forget();
  }

  save(): void {
    this.playlist.save();
    this.places.record();
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
}
