import { ResolvedTheme } from './themes/registry';

export const BRIGHTNESS_MIN = -3;

// Light panels are already white, so light stops one step above today (#832).
// The no-flash script in index.html mirrors these bounds; keep them in step.
export const BRIGHTNESS_MAX: Record<ResolvedTheme, number> = { light: 1, dark: 3 };

/** The bar always draws the full dark range; light marks its unreachable cells. */
export const BRIGHTNESS_CELLS: readonly number[] = [-3, -2, -1, 0, 1, 2, 3];

export function brightnessKey(theme: ResolvedTheme): string {
  return `sfr.brightness.${theme}`;
}

export function clampBrightness(theme: ResolvedTheme, value: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.max(BRIGHTNESS_MIN, Math.min(BRIGHTNESS_MAX[theme], Math.trunc(value)));
}
