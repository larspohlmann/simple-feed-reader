export const AVATAR_TONE_COUNT = 4;

export function avatarToneOf(seed: string): number {
  let hash = 0;
  for (const character of seed) {
    hash = (hash * 31 + (character.codePointAt(0) ?? 0)) >>> 0;
  }
  return hash % AVATAR_TONE_COUNT;
}
