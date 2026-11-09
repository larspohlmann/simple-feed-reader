import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from '../../../core/auth/auth.service';

/** Whether the catalog offers anything to pick, read off `/api/me` so the reader never downloads the catalog to find out (#2138). */
@Injectable({ providedIn: 'root' })
export class CatalogAvailability {
  private readonly auth = inject(AuthService);

  /** A failed account load resolves as nothing to offer: never redirect into a picker that may not load. */
  readonly resolved = computed(() => this.auth.user() !== null || this.auth.accountLoadFailed());

  readonly hasEntries = computed(() => this.auth.user()?.catalog.hasEntries ?? false);
}
