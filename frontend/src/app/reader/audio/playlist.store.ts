import { Injectable } from '@angular/core';
import type { AudioTrack } from '../audio-player.service';
import { Playlist } from './playlist';

export interface SavedPlaylist {
  playlist: Playlist;
  position: number;
}

const KEY = 'sfr.audio';

/** Where the playlist outlives a reload. Device-local for now (#1429); a server-side
 *  playlist that syncs across devices replaces this class and nothing else. */
@Injectable({ providedIn: 'root' })
export class PlaylistStore {
  load(): SavedPlaylist | null {
    try {
      const raw = localStorage.getItem(KEY);
      return raw ? this.parse(JSON.parse(raw)) : null;
    } catch {
      this.clear();
      return null;
    }
  }

  save(saved: SavedPlaylist): void {
    const { playlist, position } = saved;
    localStorage.setItem(KEY, JSON.stringify({ ...playlist, position }));
  }

  clear(): void {
    localStorage.removeItem(KEY);
  }

  private parse(stored: StoredShape): SavedPlaylist {
    const position = typeof stored.position === 'number' ? stored.position : 0;
    if (stored.track) return { playlist: { tracks: [stored.track], index: 0 }, position };
    const tracks = stored.tracks ?? [];
    if (!Array.isArray(tracks) || tracks.length === 0) throw new Error('empty playlist');
    const index = Number.isInteger(stored.index) ? Number(stored.index) : 0;
    return {
      playlist: { tracks, index: Math.max(0, Math.min(tracks.length - 1, index)) },
      position,
    };
  }
}

/** Today's `{ tracks, index, position }`, or the single-track `{ track, position }` of #915. */
interface StoredShape {
  tracks?: AudioTrack[];
  index?: number;
  track?: AudioTrack;
  position?: number;
}
