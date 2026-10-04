// The no-flash script in index.html mirrors these.
export const TEXT_SIZE_STEPS: readonly number[] = [90, 100, 110, 120, 130, 140, 150];
export const TEXT_SIZE_DEFAULT = 100;
export const TEXT_SIZE_KEY = 'sfr.textSize';

export function parseTextSize(raw: string | null): number {
  const percent = Number(raw);
  return TEXT_SIZE_STEPS.includes(percent) ? percent : TEXT_SIZE_DEFAULT;
}
