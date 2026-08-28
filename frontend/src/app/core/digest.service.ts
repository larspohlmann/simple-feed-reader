// src/app/core/digest.service.ts
import { Injectable, inject, signal } from '@angular/core';
import { CurrentUser } from './auth.service';
import { DIGEST_WRITER, DigestConfig } from './digest-writer';

const DEFAULT_ENABLED = false;
const DEFAULT_CADENCE = 'daily';
const DEFAULT_SEND_HOUR = 8;
const DEFAULT_WEEKDAY = 1;

/**
 * Per-account digest settings, mirroring `PreferencesService`: the account is
 * the source of truth, the signals are a cache the UI reads, and a write
 * applies locally first so a toggle does not wait on the network. Unlike
 * `PreferencesService`, the four fields form one config the backend accepts
 * together, so every setter writes the whole thing back, not just the field
 * that changed.
 */
@Injectable({ providedIn: 'root' })
export class DigestService {
  private readonly writer = inject(DIGEST_WRITER);

  readonly enabled = signal(DEFAULT_ENABLED);
  readonly cadence = signal<'daily' | 'weekly'>(DEFAULT_CADENCE);
  readonly sendHour = signal(DEFAULT_SEND_HOUR);
  readonly weekday = signal(DEFAULT_WEEKDAY);

  /** True when the value applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  setEnabled(enabled: boolean): void {
    this.enabled.set(enabled);
    this.writeAll();
  }

  setCadence(cadence: 'daily' | 'weekly'): void {
    this.cadence.set(cadence);
    this.writeAll();
  }

  setSendHour(sendHour: number): void {
    this.sendHour.set(sendHour);
    this.writeAll();
  }

  setWeekday(weekday: number): void {
    this.weekday.set(weekday);
    this.writeAll();
  }

  /**
   * Take the account's values, typically right after `AuthService.loadMe()`.
   * Caches only — a value that just arrived from the server is never PATCHed
   * straight back to it.
   */
  adopt(user: CurrentUser): void {
    this.enabled.set(user.preferences.digest.enabled);
    this.cadence.set(user.preferences.digest.cadence);
    this.sendHour.set(user.preferences.digest.sendHour);
    this.weekday.set(user.preferences.digest.weekday);
  }

  /**
   * Drops the signed-out account's cached digest settings. Per-account, like
   * `PreferencesService`: leaving it set would let the next signed-in account
   * see the previous one's values until (or unless) its own `loadMe()`
   * resolves.
   */
  reset(): void {
    this.enabled.set(DEFAULT_ENABLED);
    this.cadence.set(DEFAULT_CADENCE);
    this.sendHour.set(DEFAULT_SEND_HOUR);
    this.weekday.set(DEFAULT_WEEKDAY);
    this.saveFailed.set(false);
  }

  private writeAll(): void {
    this.saveFailed.set(false);

    const config: DigestConfig = {
      enabled: this.enabled(),
      cadence: this.cadence(),
      sendHour: this.sendHour(),
      weekday: this.weekday(),
    };

    this.writer.write(config).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }
}
