import { CINEMA_BOX_CLASS } from './reader-cinema';

export const EMBED_BOX = '.reader-embed';
export const CINEMA_BOX = `.${CINEMA_BOX_CLASS}`;

export function playerBoxOf(frame: Element): Element {
  return frame.closest(CINEMA_BOX) ?? frame.closest(EMBED_BOX) ?? frame;
}
