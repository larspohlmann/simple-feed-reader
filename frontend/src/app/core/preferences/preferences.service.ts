import { Injectable, computed, inject, linkedSignal, signal } from '@angular/core';
import { userIdClaim } from '../auth/account-identity';
import { CurrentUser } from '../auth/auth.service';
import { TokenStore } from '../auth/token.store';
import { PREFERENCES_WRITER, writeTracked } from './preferences-writer';
import { readDeviceValue, userDeviceKey, writeDeviceValue } from './user-device-storage';

const TRENDING_ENABLED = 'trending-enabled';

function cachedTrendingEnabled(key: string | null): boolean {
  return readDeviceValue(key) !== 'false';
}

/**
 * Per-account settings, mirroring `LanguageService`: the account is the source
 * of truth, the signal is a cache the UI reads, and a write applies locally
 * first so the toggle does not wait on the network.
 */
@Injectable({ providedIn: 'root' })
export class PreferencesService {
  private readonly writer = inject(PREFERENCES_WRITER);
  /** The token, not `UserDeviceStorage`: that one reaches `AuthService`, which injects this service. */
  private readonly tokens = inject(TokenStore);

  /**
   * No "not loaded yet" state: a deep link to /settings renders this at its
   * `false` default before `AuthService.loadMe()` resolves and `adopt()`
   * overwrites it — a real window, not hypothetical (settings-shell only
   * fetches when `auth.user()` is null). Benign only because `false` is the
   * safe default for THIS preference. Trending, on by default, paints from a
   * per-account cache instead, so its window shows the account's last choice.
   */
  readonly scrapeFallbackEnabled = signal(false);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  private readonly trendingCacheKey = computed(() => {
    const userId = userIdClaim(this.tokens.token());
    return userId === null ? null : userDeviceKey(userId, TRENDING_ENABLED);
  });
  /** The account's cached choice, read once per account, until `adopt()` or a toggle overrides it. */
  private readonly trending = linkedSignal(() => cachedTrendingEnabled(this.trendingCacheKey()));
  readonly trendingEnabled = this.trending.asReadonly();
  /** Whether `trendingEnabled` is the account's value rather than this device's cache of it. */
  private readonly trendingFromAccount = linkedSignal(() => {
    this.trendingCacheKey();
    return false;
  });
  readonly trendingConfirmed = this.trendingFromAccount.asReadonly();

  readonly trendingSaveFailed = signal(false);

  setScrapeFallbackEnabled(enabled: boolean): void {
    this.scrapeFallbackEnabled.set(enabled);
    writeTracked(this.writer, { scrapeFallbackEnabled: enabled }, this.saveFailed);
  }

  setTrendingEnabled(enabled: boolean): void {
    this.applyTrending(enabled);
    writeTracked(this.writer, { trendingEnabled: enabled }, this.trendingSaveFailed);
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.scrapeFallbackEnabled.set(user.preferences.scrapeFallbackEnabled);
    this.applyTrending(user.preferences.trendingEnabled ?? true);
  }

  /**
   * Drops the signed-out account's cached preference. Unlike `LanguageService`
   * -- language is a deliberate per-device cache that outlives a session --
   * this preference is per-account: leaving it set would let the next signed-in
   * account see a stale value until its own `loadMe()` resolves, or forever if
   * that request fails.
   */
  reset(): void {
    this.scrapeFallbackEnabled.set(false);
    this.saveFailed.set(false);
    this.trending.set(cachedTrendingEnabled(this.trendingCacheKey()));
    this.trendingFromAccount.set(false);
    this.trendingSaveFailed.set(false);
  }

  private applyTrending(enabled: boolean): void {
    this.trending.set(enabled);
    this.trendingFromAccount.set(true);
    const key = this.trendingCacheKey();
    if (key !== null) writeDeviceValue(key, enabled ? null : 'false');
  }
}
