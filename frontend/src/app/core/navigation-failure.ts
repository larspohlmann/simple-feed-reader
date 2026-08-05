// src/app/core/navigation-failure.ts
import { Injectable, Signal, signal } from '@angular/core';
import { revealBootErrorSurface } from './boot-error-surface';

/**
 * The one place that decides how a broken navigation reaches the user (#285).
 *
 * Before anything has rendered, the only thing that can carry a message is the
 * static `#boot-error` div in index.html — no component tree exists to host a
 * banner, which is why that surface exists at all (#280). After a successful
 * navigation the app is alive and a working page is on screen, so replacing it
 * with a full-page "The app could not start" would be both heavy-handed and
 * false; the banner reports the failure and leaves the page standing.
 *
 * Two callers report here: the navigation watchdog (a stall, which raises no
 * router event) and app.config.ts's `withNavigationErrorHandler` (a rejection).
 */
@Injectable({ providedIn: 'root' })
export class NavigationFailureReporter {
  private readonly hasRendered = signal(false);
  private readonly bannerVisible = signal(false);

  readonly failed: Signal<boolean> = this.bannerVisible.asReadonly();

  report(error: unknown): void {
    if (!this.hasRendered()) {
      revealBootErrorSurface(error);
      return;
    }
    console.error(error);
    this.bannerVisible.set(true);
  }

  /**
   * A completed navigation both proves the app can render and retracts any
   * banner still on screen: a stall the watchdog gave up on may resolve late,
   * and the user should not be told to retry something that just worked.
   */
  noteNavigationSucceeded(): void {
    this.hasRendered.set(true);
    this.bannerVisible.set(false);
  }
}
