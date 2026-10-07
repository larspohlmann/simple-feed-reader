import type { AudioTrack } from '../audio-player.service';

/** The player's queue: tracks in play order, `index` the current one (-1 when empty).
 *  A track is identified by its url and appears at most once (#1429). */
export interface Playlist {
  readonly tracks: readonly AudioTrack[];
  readonly index: number;
}

export const EMPTY_PLAYLIST: Playlist = { tracks: [], index: -1 };

export function currentTrack(playlist: Playlist): AudioTrack | null {
  return playlist.tracks[playlist.index] ?? null;
}

export function indexOf(playlist: Playlist, url: string): number {
  return playlist.tracks.findIndex((track) => track.url === url);
}

export function append(playlist: Playlist, track: AudioTrack): Playlist {
  if (indexOf(playlist, track.url) !== -1) return playlist;
  const tracks = [...playlist.tracks, track];
  return { tracks, index: playlist.index === -1 ? 0 : playlist.index };
}

/** Listen: a queued track becomes current; any other goes right after the current one. */
export function insertNext(playlist: Playlist, track: AudioTrack): Playlist {
  const queued = indexOf(playlist, track.url);
  if (queued !== -1) return select(playlist, queued);
  const at = playlist.index + 1;
  const tracks = [...playlist.tracks.slice(0, at), track, ...playlist.tracks.slice(at)];
  return { tracks, index: at };
}

export function select(playlist: Playlist, index: number): Playlist {
  if (index < 0 || index >= playlist.tracks.length) return playlist;
  return { ...playlist, index };
}

export function move(playlist: Playlist, from: number, to: number): Playlist {
  const last = playlist.tracks.length - 1;
  const target = Math.max(0, Math.min(last, to));
  if (from < 0 || from > last || from === target) return playlist;
  const current = currentTrack(playlist);
  const tracks = [...playlist.tracks];
  const [moved] = tracks.splice(from, 1);
  tracks.splice(target, 0, moved);
  return { tracks, index: current ? tracks.indexOf(current) : -1 };
}

/** Removing the current track hands its place to the next one, or the previous when it was last. */
export function remove(playlist: Playlist, url: string): Playlist {
  const at = indexOf(playlist, url);
  if (at === -1) return playlist;
  const tracks = playlist.tracks.filter((_, position) => position !== at);
  if (tracks.length === 0) return EMPTY_PLAYLIST;
  const index = at < playlist.index ? playlist.index - 1 : playlist.index;
  return { tracks, index: Math.min(index, tracks.length - 1) };
}
