import { EffectRef, effect } from '@angular/core';
import type { ListAutoplayCoordinator } from '../../../../media/autoplay/list-autoplay-coordinator';

const PLAYER_VIDEOS = 'video:not([data-animated])';

export const MEDIA_PAUSE = 'media-pause';

/** Pauses every video under the root and tells each media block to put its player away. */
export function pauseMedia(root: ParentNode): void {
  const videos = root.querySelectorAll<HTMLVideoElement>(PLAYER_VIDEOS);
  for (const video of Array.from(videos)) video.pause();
  for (const block of Array.from(root.querySelectorAll('app-entry-media'))) {
    block.dispatchEvent(new Event(MEDIA_PAUSE));
  }
}

/** Must run in an injection context. */
export function pauseMediaWhileAnEntryIsOpen(
  openEntryId: () => number | null,
  autoplay: Pick<ListAutoplayCoordinator, 'suspend' | 'resume'>,
  root: HTMLElement,
): EffectRef {
  return effect(() => {
    if (openEntryId() === null) {
      autoplay.resume();
      return;
    }
    autoplay.suspend();
    pauseMedia(root);
  });
}
