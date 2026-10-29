import { CINEMA_BOX_CLASS } from './reader-cinema';

export const EMBED_BOX = '.reader-embed';

/** The outermost box a player sits in: its cinema box, else its embed box, else the frame. */
export function playerBoxOf(frame: Element): Element {
  return frame.closest(`.${CINEMA_BOX_CLASS}`) ?? frame.closest(EMBED_BOX) ?? frame;
}
