// src/app/core/preferences.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { PREFERENCES_WRITER } from './preferences-writer';

/**
 * Per-account settings, mirroring `LanguageService`: the account is the source
 * of truth, the signal is a cache the UI reads, and a write applies locally
 * first so the toggle does not wait on the network.
 */
@Injectable({ providedIn: 'root' })
export class PreferencesService {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly scrapeFallbackEnabled = signal(false);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  setScrapeFallbackEnabled(enabled: boolean): void {
    this.scrapeFallbackEnabled.set(enabled);
    this.saveFailed.set(false);

    this.writer.write(enabled).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.scrapeFallbackEnabled.set(user.preferences.scrapeFallbackEnabled);
  }
}
