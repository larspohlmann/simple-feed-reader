import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from '../../../core/auth/auth.service';

/** Whether the catalog offers anything to pick, read off `/api/me` so the reader never downloads the catalog to find out (#2138). */
@Injectable({ providedIn: 'root' })
export class CatalogAvailability {
  private readonly auth = inject(AuthService);

  /** A failed account load resolves as nothing to offer: never redirect into a picker that may not load. */
  readonly resolved = this.auth.accountResolved;

  readonly hasEntries = computed(() => this.auth.user()?.catalog.hasEntries ?? false);

  /** Only a loaded account can prove the catalog empty; an unanswered or failed load is unknown. */
  readonly knownEmpty = computed(() => {
    const user = this.auth.user();
    return user !== null && !user.catalog.hasEntries;
  });
}
