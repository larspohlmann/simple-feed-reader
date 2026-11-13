export type Handedness = 'right' | 'left';

export const HANDEDNESS: readonly Handedness[] = ['right', 'left'];

export const DEFAULT_HANDEDNESS: Handedness = 'right';

/** The per-device paint cache; the account is the record. */
export const HANDEDNESS_KEY = 'sfr.handedness';

export function asHandedness(value: string | null): Handedness | null {
  return HANDEDNESS.includes(value as Handedness) ? (value as Handedness) : null;
}
