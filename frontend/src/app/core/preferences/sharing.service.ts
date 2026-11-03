import { Injectable, computed, inject, signal } from '@angular/core';
import { CurrentUser } from '../auth/auth.service';
import { SHARING_EVERYTHING, SHARING_KEY, SharingSettings, asSharingSettings } from './sharing';
import { SHARING_WRITER } from './sharing-writer';

/** The account is the record; `localStorage` is a paint cache, as for `MagazineStyleService`. */
@Injectable({ providedIn: 'root' })
export class SharingService {
  private readonly writer = inject(SHARING_WRITER);
  private readonly settings = signal<SharingSettings>(this.cached());

  readonly enabled = computed(() => this.settings().enabled);
  readonly hidden = computed((): ReadonlySet<string> => new Set(this.settings().hiddenTargets));
  readonly saveFailed = signal(false);

  setEnabled(enabled: boolean): void {
    this.save({ ...this.settings(), enabled });
  }

  /** Keeps ids this device does not list, so another device's hidden targets survive. */
  setTargetShown(id: string, shown: boolean): void {
    const others = this.settings().hiddenTargets.filter((hiddenId) => hiddenId !== id);
    this.save({ ...this.settings(), hiddenTargets: shown ? others : [...others, id] });
  }

  /** Caches only: a value that just arrived from the server is never written straight back. */
  adopt(user: CurrentUser): void {
    const settings = asSharingSettings(user.preferences.sharing);
    if (settings !== null) this.cache(settings);
  }

  reset(): void {
    localStorage.removeItem(SHARING_KEY);
    this.settings.set(SHARING_EVERYTHING);
    this.saveFailed.set(false);
  }

  private save(settings: SharingSettings): void {
    this.cache(settings);
    this.saveFailed.set(false);
    this.writer.write(settings).subscribe((ok) => {
      if (!ok) this.saveFailed.set(true);
    });
  }

  private cache(settings: SharingSettings): void {
    localStorage.setItem(SHARING_KEY, JSON.stringify(settings));
    this.settings.set(settings);
  }

  private cached(): SharingSettings {
    try {
      return (
        asSharingSettings(JSON.parse(localStorage.getItem(SHARING_KEY) ?? 'null')) ??
        SHARING_EVERYTHING
      );
    } catch {
      return SHARING_EVERYTHING;
    }
  }
}
