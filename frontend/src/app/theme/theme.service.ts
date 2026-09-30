import { computed, Injectable, signal } from '@angular/core';
import { ResolvedTheme, ThemeMode } from './themes/registry';

const KEY = 'sfr.theme';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  private readonly osDark = signal(this.media.matches);
  readonly mode = signal<ThemeMode>(this.readSaved());

  /** The theme on screen: the mode, or what the OS resolved `system` to. */
  readonly resolved = computed<ResolvedTheme>(() => {
    const mode = this.mode();
    return mode === 'system' ? (this.osDark() ? 'dark' : 'light') : mode;
  });

  constructor() {
    // Apply synchronously on construction (not via effect, whose flush is
    // async) so the theme is correct before the first render and assertions.
    this.applyResolved();
    this.media.addEventListener('change', () => {
      this.osDark.set(this.media.matches);
      this.applyResolved();
    });
  }

  setMode(mode: ThemeMode): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
    this.applyResolved();
  }

  private readSaved(): ThemeMode {
    const saved = localStorage.getItem(KEY);
    return saved === 'light' || saved === 'dark' || saved === 'system' ? saved : 'system';
  }

  private applyResolved(): void {
    document.documentElement.setAttribute('data-theme', this.resolved());
  }
}
