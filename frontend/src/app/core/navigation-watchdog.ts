// src/app/core/navigation-watchdog.ts
import { DestroyRef, inject } from '@angular/core';
import {
  NavigationCancel,
  NavigationEnd,
  NavigationError,
  NavigationStart,
  Router,
} from '@angular/router';
import { NavigationFailureReporter } from './navigation-failure';

/**
 * How long a navigation may run, once one has already completed, before the
 * app admits it is not coming (#285).
 *
 * Shorter than the boot watchdog's 15 s in index.html, deliberately: that one
 * covers a cold start on a radio that may still be reconnecting, while this one
 * starts from a connection that just worked and waits on a single chunk. A
 * false positive costs little — a completed navigation retracts the banner.
 */
export const NAVIGATION_DEADLINE_MS = 8000;

/**
 * Turns a navigation that never terminates into a visible failure (#285). A
 * hung `import()` never rejects, so the router raises no `NavigationError`
 * and the click stays silently dead with the previous page on screen; the
 * same holds for a guard or resolver that never settles. Watching the event
 * stream catches all of these without every route needing its own wrapper.
 *
 * The bootstrap navigation is deliberately unsupervised: it can legitimately
 * take longer than 8 s on a cold, still-settling connection, and that window
 * already belongs to the #282 boot watchdog's 15 s in index.html. So this
 * watchdog stays disarmed until it has seen a first `NavigationEnd`.
 */
export function startNavigationWatchdog(): void {
  const router = inject(Router);
  const reporter = inject(NavigationFailureReporter);
  let timer: ReturnType<typeof setTimeout> | undefined;
  let hasCompletedANavigation = false;

  const cancel = (): void => {
    clearTimeout(timer);
    timer = undefined;
  };

  const subscription = router.events.subscribe((event) => {
    if (event instanceof NavigationStart) {
      // Re-arm rather than stack: a second navigation supersedes the first.
      cancel();
      if (!hasCompletedANavigation) {
        // The bootstrap navigation: leave it to the #282 boot watchdog's 15 s.
        return;
      }
      timer = setTimeout(
        () => reporter.report(new Error(`Navigation to ${event.url} stalled.`)),
        NAVIGATION_DEADLINE_MS,
      );
      return;
    }
    if (event instanceof NavigationEnd) {
      cancel();
      hasCompletedANavigation = true;
      reporter.noteNavigationSucceeded();
      return;
    }
    if (event instanceof NavigationCancel || event instanceof NavigationError) {
      // A cancel is a guard redirect, and an error already reaches the user
      // through withNavigationErrorHandler; neither is this watchdog's to
      // report, but both end the navigation the deadline was watching.
      cancel();
    }
  });

  inject(DestroyRef).onDestroy(() => {
    cancel();
    subscription.unsubscribe();
  });
}
