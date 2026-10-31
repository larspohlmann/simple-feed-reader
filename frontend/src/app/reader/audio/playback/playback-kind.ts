import { embedMediaOfUrl } from '../embed-media';

export type PlaybackKind = 'file' | 'youtube' | 'soundcloud' | 'mixcloud';
export type FrameKind = Exclude<PlaybackKind, 'file'>;

/** A stored url from any earlier playlist is a file, so an unknown shape never breaks a saved playlist. */
export function playbackKindOf(url: string): PlaybackKind {
  return embedMediaOfUrl(url)?.kind ?? 'file';
}
