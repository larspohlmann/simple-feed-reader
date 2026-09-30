import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Subscription } from 'rxjs';
import { Problem, parseProblem } from '../core/problem';
import { onIdentityChange } from '../core/session-identity';
import { CatalogApi } from './catalog-api';
import { CatalogCategoryDto } from './catalog.models';

/**
 * The catalog, fetched once per session and shared.
 *
 * Two consumers with different needs: the reader shell asks only "is there
 * anything to show?" before it decides whether to send a new user to the picker,
 * and the picker itself renders the whole thing. One store means the shell's
 * check does not cost an extra round trip when the redirect does happen.
 */
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

  /** Held so that invalidate() can cancel it: a response issued for the
   *  previous user must never land in the next user's store. */
  private inFlight: Subscription | null = null;

  constructor() {
    // Every feed carries a per-user `subscribed` flag, so a change of identity
    // does not make this cache stale — it makes it wrong (#263).
    onIdentityChange(() => this.invalidate());
  }

  load(): void {
    if (this.loading() || this.resolved()) return;
    this.loading.set(true);
    this.error.set(null);
    this.inFlight = this.api.load().subscribe({
      next: (r) => {
        this.categories.set(r.categories);
        this.loading.set(false);
        this.resolved.set(true);
      },
      error: (e: HttpErrorResponse) => {
        // Resolve as empty on failure: a redirect into a picker that cannot load
        // is worse than leaving the user in the reader with a link.
        this.categories.set([]);
        this.error.set(parseProblem(e));
        this.loading.set(false);
        this.resolved.set(true);
      },
    });
  }

  /** Forget the cached catalog so the next load() refetches — used after a
   *  successful subscribe, since every picked feed is now `subscribed`, and
   *  whenever the signed-in identity changes. */
  invalidate(): void {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.loading.set(false);
    this.resolved.set(false);
    this.categories.set([]);
  }
}
