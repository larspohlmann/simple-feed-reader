import { isTextEntryTarget } from '../../../shared/text-entry-target';

const LANDSCAPE_PLAYER = '.reader-embed--landscape:not(.reader-embed--audio), video';
export const CINEMA_BOX_CLASS = 'reader-cinema';

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

/** `t` widens or narrows the video in view, else the first one, like YouTube's theatre key. */
export function toggleCinemaByKey(event: KeyboardEvent, host: HTMLElement | undefined): void {
  if (!host || !isCinemaKey(event)) return;
  const toggles = Array.from(
    host.querySelectorAll<HTMLButtonElement>('.reader-cinema__toggle'),
  ).filter(isOffered);
  const toggle = toggles.find(isInViewport) ?? toggles[0];
  if (!toggle) return;
  event.preventDefault();
  toggle.click();
}

function isCinemaKey(event: KeyboardEvent): boolean {
  if (event.key !== 't' || event.defaultPrevented) return false;
  if (event.metaKey || event.ctrlKey || event.altKey) return false;
  return !isTextEntryTarget(event.target);
}

function isOffered(toggle: HTMLElement): boolean {
  return getComputedStyle(toggle).display !== 'none';
}

function isInViewport(toggle: HTMLElement): boolean {
  const { top, bottom } = toggle.getBoundingClientRect();
  return bottom > 0 && top < window.innerHeight;
}
