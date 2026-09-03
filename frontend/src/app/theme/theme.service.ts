import { Injectable, signal } from '@angular/core';
import { ResolvedTheme, ThemeMode } from './themes/registry';

const KEY = 'sfr.theme';

@Injectable({ providedIn: 'root' })
export class ThemeService {
  private readonly media = window.matchMedia('(prefers-color-scheme: dark)');
  readonly mode = signal<ThemeMode>(this.readSaved());

  /** The theme on screen: the mode, or what the OS resolved `system` to. */
  readonly resolved = signal<ResolvedTheme>(this.resolve());

  constructor() {
    // Apply synchronously on construction (not via effect, whose flush is
    // async) so the theme is correct before the first render and assertions.
    this.applyResolved();
    this.media.addEventListener('change', () => {
      if (this.mode() === 'system') this.applyResolved();
    });
  }

  setMode(mode: ThemeMode): void {
    localStorage.setItem(KEY, mode);
    this.mode.set(mode);
    this.applyResolved();
  }

  private readSaved(): ThemeMode {
    const v = localStorage.getItem(KEY);
    return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
  }

  private resolve(): ResolvedTheme {
    const m = this.mode();
    return m === 'system' ? (this.media.matches ? 'dark' : 'light') : m;
  }

  private applyResolved(): void {
    const theme = this.resolve();
    this.resolved.set(theme);
    document.documentElement.setAttribute('data-theme', theme);
  }
}
