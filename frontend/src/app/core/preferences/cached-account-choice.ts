import { WritableSignal, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { PREFERENCES_WRITER, ReadingPreferences, writeTracked } from './preferences-writer';

type ChoiceField = 'magazineStyle' | 'handedness' | 'sidebarStyle';

interface CachedAccountChoiceOptions<Field extends ChoiceField> {
  key: string;
  choices: readonly ReadingPreferences[Field][];
  fallback: ReadingPreferences[Field];
  field: Field;
}

/**
 * The account is the record; `localStorage` is a paint cache. It answers the
 * warning in `PreferencesService`: once a choice has been cached, no later
 * window shows or writes a wrong one, so the first frame is never drawn with
 * the fallback while `loadMe()` is pending.
 */
export abstract class CachedAccountChoice<Field extends ChoiceField> {
  private readonly writer = inject(PREFERENCES_WRITER);

  readonly value: WritableSignal<ReadingPreferences[Field]>;

  /** True when the choice applied locally but the account write failed. */
  readonly saveFailed = signal(false);

  protected constructor(private readonly options: CachedAccountChoiceOptions<Field>) {
    this.value = signal(this.parse(localStorage.getItem(options.key)) ?? options.fallback);
  }

  /** Applies locally first, then writes through; a failed write is surfaced. */
  set(choice: ReadingPreferences[Field]): void {
    this.cache(choice);
    writeTracked(this.writer, { [this.options.field]: choice }, this.saveFailed);
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

  private cache(choice: ReadingPreferences[Field]): void {
    localStorage.setItem(this.options.key, choice);
    this.value.set(choice);
  }

  private parse(raw: string | null): ReadingPreferences[Field] | null {
    return this.options.choices.find((choice) => choice === raw) ?? null;
  }
}
