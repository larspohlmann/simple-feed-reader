import { ResolvedTheme } from './themes/registry';

// Light panels start white, so light only dims and its default is its top (#832).
// The no-flash script in index.html and $ranges in _brightness.scss mirror these.
export const BRIGHTNESS_MIN: Record<ResolvedTheme, number> = { light: -6, dark: -3 };
export const BRIGHTNESS_MAX: Record<ResolvedTheme, number> = { light: 0, dark: 3 };

export function brightnessKey(theme: ResolvedTheme): string {
  return `sfr.brightness.${theme}`;
}

export function clampBrightness(theme: ResolvedTheme, value: number): number {
  if (!Number.isFinite(value)) {
    return 0;
  }
  return Math.max(BRIGHTNESS_MIN[theme], Math.min(BRIGHTNESS_MAX[theme], Math.trunc(value)));
}
