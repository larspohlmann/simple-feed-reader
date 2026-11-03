import { Injectable, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, concatMap } from 'rxjs';
import { CurrentUser } from '../auth/auth.service';
import { sameIds } from '../same-ids';
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
  /** Each write carries the whole state, so they must reach the server in the order they were made. */
  private readonly writes = new Subject<SharingSettings>();

  constructor() {
    this.writes
      .pipe(
        concatMap((settings) => this.writer.write(settings)),
        takeUntilDestroyed(),
      )
      .subscribe((ok) => this.saveFailed.set(!ok));
  }

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
    if (settings !== null && !sameSettings(settings, this.settings())) this.cache(settings);
  }

  reset(): void {
    localStorage.removeItem(SHARING_KEY);
    this.settings.set(SHARING_EVERYTHING);
    this.saveFailed.set(false);
  }

  private save(settings: SharingSettings): void {
    this.cache(settings);
    this.saveFailed.set(false);
    this.writes.next(settings);
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

function sameSettings(left: SharingSettings, right: SharingSettings): boolean {
  return left.enabled === right.enabled && sameIds(left.hiddenTargets, right.hiddenTargets);
}
