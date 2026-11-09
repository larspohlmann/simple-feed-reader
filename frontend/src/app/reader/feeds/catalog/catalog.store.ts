import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { Problem, parseProblem } from '../../../core/problem';
import { onIdentityChange } from '../../../core/auth/session-identity';
import { CatalogApi } from './catalog-api';
import { CatalogCategoryDto } from './catalog.models';

/** The catalog the picker renders, fetched on every /discover visit; the reader reads `CatalogAvailability`. */
@Injectable({ providedIn: 'root' })
export class CatalogStore {
  private readonly api = inject(CatalogApi);

  readonly categories = signal<CatalogCategoryDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  /** True once a load has finished, successfully or not. Distinct from "empty":
   *  before the answer arrives, neither is known. */
  readonly resolved = signal(false);

  /** A catalog nobody has imported yet — or one whose categories are all empty —
   *  has nothing to offer, and must never be redirected into. */
  readonly hasEntries = computed(() =>
    this.categories().some((category) => category.feeds.length > 0),
  );

  /** Held so that a new load or an identity change can cancel it: a response issued for the
   *  previous user must never land in the next user's store. */
  private inFlight: Subscription | null = null;

  constructor() {
    // Every feed carries a per-user `subscribed` flag, so a change of identity
    // makes the held catalog wrong, not just stale (#263).
    onIdentityChange(() => this.clear());
  }

  /** Always fetches, so a visit never shows an earlier answer. */
  load(): void {
    this.clear();
    this.loading.set(true);
    this.error.set(null);
    this.inFlight = this.api.load().subscribe({
      next: (response) => {
        this.categories.set(response.categories);
        this.loading.set(false);
        this.resolved.set(true);
      },
      error: (error: HttpErrorResponse) => {
        // Resolve as empty on failure: a redirect into a picker that cannot load
        // is worse than leaving the user in the reader with a link.
        this.categories.set([]);
        this.error.set(parseProblem(error));
        this.loading.set(false);
        this.resolved.set(true);
      },
    });
  }

  private clear(): void {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.loading.set(false);
    this.resolved.set(false);
    this.categories.set([]);
  }
}
