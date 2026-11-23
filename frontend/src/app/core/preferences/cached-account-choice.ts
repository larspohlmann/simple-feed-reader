import { WritableSignal, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { PREFERENCES_WRITER, ReadingPreferences, writeTracked } from './preferences-writer';

interface CachedAccountChoiceOptions<T extends string> {
  key: string;
  choices: readonly T[];
  fallback: T;
  field: 'magazineStyle' | 'handedness' | 'sidebarStyle';
}

/**
 * The account is the record; `localStorage` is a paint cache. It answers the
 * warning in `PreferencesService`: once a choice has been cached, no later
 * window shows or writes a wrong one, so the first frame is never drawn with
 * the fallback while `loadMe()` is pending.
 */
export abstract class CachedAccountChoice<T extends string> {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly value: WritableSignal<T>;

  /** True when the choice applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  protected constructor(private readonly options: CachedAccountChoiceOptions<T>) {
    this.value = signal(this.parse(localStorage.getItem(options.key)) ?? options.fallback);
  }

  /** Applies locally first, then writes through; a failed write is surfaced. */
  set(choice: T): void {
    this.cache(choice);
    const change = { [this.options.field]: choice } as Partial<ReadingPreferences>;
    writeTracked(this.writer, change, this.saveFailed);
  }

  /**
   * Caches only: a value just read from the account is never written straight
   * back, and `adopt` runs on every `loadMe()`.
   */
  adopt(user: CurrentUser): void {
    const choice = this.parse(user.preferences[this.options.field]);
    if (choice === null) return;
    this.cache(choice);
  }

  /** Cache included: the next account must not inherit this one's choice. */
  reset(): void {
    localStorage.removeItem(this.options.key);
    this.value.set(this.options.fallback);
    this.saveFailed.set(false);
  }

  private cache(choice: T): void {
    localStorage.setItem(this.options.key, choice);
    this.value.set(choice);
  }

  private parse(raw: string | null): T | null {
    return this.options.choices.find((choice) => choice === raw) ?? null;
  }
}
