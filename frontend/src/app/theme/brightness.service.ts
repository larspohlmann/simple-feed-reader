import { computed, effect, inject, Injectable, signal } from '@angular/core';
import { BRIGHTNESS_MAX, BRIGHTNESS_MIN, brightnessKey, clampBrightness } from './brightness';
import { ThemeService } from './theme.service';
import { ResolvedTheme } from './themes/registry';

@Injectable({ providedIn: 'root' })
export class BrightnessService {
  private readonly theme = inject(ThemeService);
  private readonly saved = signal<Record<ResolvedTheme, number>>({
    light: readSaved('light'),
    dark: readSaved('dark'),
  });

  /** The step of the theme on screen; under `system` that is whatever the OS resolved. */
  readonly step = computed(() => this.saved()[this.theme.resolved()]);
  readonly min = BRIGHTNESS_MIN;
  readonly max = computed(() => BRIGHTNESS_MAX[this.theme.resolved()]);

  constructor() {
    // index.html's no-flash script paints the first frame; this keeps the
    // attribute in step afterwards, including when the OS flips the scheme.
    effect(() => document.documentElement.setAttribute('data-brightness', String(this.step())));
  }

  set(step: number): void {
    const theme = this.theme.resolved();
    const value = clampBrightness(theme, step);
    localStorage.setItem(brightnessKey(theme), String(value));
    this.saved.update((steps) => ({ ...steps, [theme]: value }));
  }

  increase(): void {
    this.set(this.step() + 1);
  }

  decrease(): void {
    this.set(this.step() - 1);
  }

  reset(): void {
    this.set(0);
  }
}

function readSaved(theme: ResolvedTheme): number {
  const saved = localStorage.getItem(brightnessKey(theme));
  return saved === null ? 0 : clampBrightness(theme, Number(saved));
}
