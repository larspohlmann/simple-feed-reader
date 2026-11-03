export interface SharingSettings {
  readonly enabled: boolean;
  readonly hiddenTargets: readonly string[];
}

export const SHARING_EVERYTHING: SharingSettings = { enabled: true, hiddenTargets: [] };

/** The per-device paint cache, not the record: the first article frame after a cold start must not flash a share button. */
export const SHARING_KEY = 'sfr.sharing';

export function asSharingSettings(value: unknown): SharingSettings | null {
  if (typeof value !== 'object' || value === null) return null;
  const { enabled, hiddenTargets } = value as Record<string, unknown>;
  if (typeof enabled !== 'boolean' || !Array.isArray(hiddenTargets)) return null;
  if (!hiddenTargets.every((id) => typeof id === 'string')) return null;
  return { enabled, hiddenTargets: hiddenTargets as string[] };
}
