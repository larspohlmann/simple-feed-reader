export interface SharingSettings {
  readonly enabled: boolean;
  readonly hiddenTargets: readonly string[];
  readonly rememberShares: boolean;
}

export const SHARING_EVERYTHING: SharingSettings = {
  enabled: true,
  hiddenTargets: [],
  rememberShares: true,
};

/** The per-device paint cache, not the record: the first article frame after a cold start must not flash a share button. */
export const SHARING_KEY = 'sfr.sharing';

export function asSharingSettings(value: unknown): SharingSettings | null {
  if (typeof value !== 'object' || value === null) return null;
  const { enabled, hiddenTargets, rememberShares } = value as Record<string, unknown>;
  if (typeof enabled !== 'boolean' || !Array.isArray(hiddenTargets)) return null;
  if (!hiddenTargets.every((id) => typeof id === 'string')) return null;
  return {
    enabled,
    hiddenTargets: hiddenTargets as string[],
    rememberShares: typeof rememberShares === 'boolean' ? rememberShares : true,
  };
}
