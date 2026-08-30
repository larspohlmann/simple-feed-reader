// src/app/core/magazine-style.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { MAGAZINE_STYLE_KEY, MagazineStyle, asMagazineStyle } from './magazine-style';
import { MAGAZINE_STYLE_WRITER } from './magazine-style-writer';

/**
 * The account is the record; `localStorage` is a paint cache. It answers the
 * warning in `PreferencesService`: the first frame comes from the last known
 * style, so no window exists in which a wrong value is shown or written.
 */
@Injectable({ providedIn: 'root' })
export class MagazineStyleService {
  private readonly writer = inject(MAGAZINE_STYLE_WRITER);

  readonly style = signal<MagazineStyle>(this.cached());

  /** True when the style applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  /** Applies locally first, then writes through; a failed write is surfaced. */
  set(style: MagazineStyle): void {
    this.cache(style);
    this.saveFailed.set(false);

    this.writer.write(style).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /**
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it, and `adopt` runs on every `loadMe()`.
   */
  adopt(user: CurrentUser): void {
    const style = asMagazineStyle(user.preferences.magazineStyle);
    if (style === null) return;
    this.cache(style);
  }

  /**
   * Cache included: unlike the language, this is per-account, so leaving it set
   * would show the next account the previous one's reader.
   */
  reset(): void {
    localStorage.removeItem(MAGAZINE_STYLE_KEY);
    this.style.set('boxed');
    this.saveFailed.set(false);
  }

  private cache(style: MagazineStyle): void {
    localStorage.setItem(MAGAZINE_STYLE_KEY, style);
    this.style.set(style);
  }

  private cached(): MagazineStyle {
    return asMagazineStyle(localStorage.getItem(MAGAZINE_STYLE_KEY)) ?? 'boxed';
  }
}
