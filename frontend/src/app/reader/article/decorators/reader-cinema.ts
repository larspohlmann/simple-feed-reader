import { CINEMA_BOX_CLASS } from './player-box';

const LANDSCAPE_PLAYER = '.reader-embed--landscape:not(.reader-embed--audio), video';

/** Wraps every landscape player in a cinema box its player bar can widen past the reading measure (#1479). Idempotent. */
export function wrapCinemaBoxes(host: HTMLElement): void {
  for (const player of Array.from(host.querySelectorAll<HTMLElement>(LANDSCAPE_PLAYER))) {
    if (player.parentElement?.classList.contains(CINEMA_BOX_CLASS)) continue;
    const box = document.createElement('div');
    box.className = CINEMA_BOX_CLASS;
    player.replaceWith(box);
    box.append(player);
  }
}
