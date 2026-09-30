import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { CatalogStore } from '../catalog/catalog.store';
import { OnboardingSkip } from '../catalog/onboarding-skip';
import { RefreshService } from '../refresh.service';

@Injectable()
export class ReaderOnboarding {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly catalog = inject(CatalogStore);
  private readonly skip = inject(OnboardingSkip);
  private readonly refresh = inject(RefreshService);

  private readonly catalogOffered = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  private readonly awaitingFirstFetch = computed(
    () =>
      this.subscriptions.resolved() &&
      this.subscriptions.subscriptions().length > 0 &&
      this.subscriptions
        .subscriptions()
        .every((subscription) => subscription.lastFetchedAt === null),
  );

  private readonly sweptOnce = signal(false);
  /** Unlike the permanent `sweptOnce` latch, clears once the sweep lands without error. */
  private readonly sweepInFlight = signal(false);
  readonly sweeping = this.sweepInFlight.asReadonly();

  readonly showFetchProgress = computed(() => this.sweeping() && this.refresh.failure() === null);

  /** A failed load also resolves empty; `error()` keeps that from reading as onboarding. */
  private readonly emptySubscriptionsNeedingOnboarding = computed(
    () =>
      this.subscriptions.resolved() &&
      !this.subscriptions.error() &&
      this.subscriptions.subscriptions().length === 0 &&
      !this.skip.wasSkipped(),
  );

  /** An unanswered catalog counts as running: the redirect is still undecided. */
  readonly running = computed(() => {
    if (this.awaitingFirstFetch() || this.sweeping()) return true;
    if (!this.emptySubscriptionsNeedingOnboarding()) return false;
    if (!this.catalog.resolved()) return true;
    return this.catalogOffered();
  });

  constructor() {
    effect(() => {
      if (this.auth.isAdmin()) untracked(() => this.catalog.load());
    });

    effect(() => {
      if (!this.emptySubscriptionsNeedingOnboarding()) return;
      untracked(() => this.catalog.load());
      if (!this.catalogOffered()) return;
      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // Driven by state, not a call: run() early-returns while running, so a call could be lost.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce()) return;
      this.sweptOnce.set(true);
      this.sweepInFlight.set(true);
      this.refresh.run();
    });

    effect(() => {
      if (this.sweeping() && !this.refresh.running() && this.refresh.failure() === null) {
        untracked(() => this.sweepInFlight.set(false));
      }
    });
  }
}
