import { PLAYER_VIDEOS } from '../../../../media/player-videos';

export const MEDIA_PAUSE = 'media-pause';

/** Pauses every video under the root and tells each media block to put its player away. */
export function pauseMedia(root: ParentNode): void {
  const videos = root.querySelectorAll<HTMLVideoElement>(PLAYER_VIDEOS);
  for (const video of Array.from(videos)) video.pause();
  for (const block of Array.from(root.querySelectorAll('app-entry-media'))) {
    block.dispatchEvent(new Event(MEDIA_PAUSE));
  }
}
