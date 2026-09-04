import { ResolvedTheme } from './themes/registry';

// Light starts at full brightness and only dims, so its default sits at the top
// of a longer downward range; dark brightens and dims around today (#832). Both
// ranges span seven steps, so the stepper reads as one bar in either theme. The
// no-flash script in index.html mirrors these bounds; keep them in step.
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
