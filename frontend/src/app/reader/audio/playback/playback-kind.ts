import { isSoundCloudPlayerUrl, youTubeVideoId } from '../embed-media';

export type PlaybackKind = 'file' | 'youtube' | 'soundcloud';

/** A stored url from any earlier playlist is a file, so an unknown shape never breaks a saved playlist. */
export function playbackKindOf(url: string): PlaybackKind {
  if (youTubeVideoId(url) !== null) return 'youtube';
  if (isSoundCloudPlayerUrl(url)) return 'soundcloud';
  return 'file';
}
