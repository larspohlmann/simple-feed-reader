export type Handedness = 'right' | 'left';

export const HANDEDNESS: readonly Handedness[] = ['right', 'left'];

/** The per-device paint cache, not the record, for the reason `MAGAZINE_STYLE_KEY` gives. */
export const HANDEDNESS_KEY = 'sfr.handedness';

export function asHandedness(value: string | null): Handedness | null {
  return HANDEDNESS.includes(value as Handedness) ? (value as Handedness) : null;
}
