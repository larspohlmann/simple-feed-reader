import { Signal, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { NavigationEnd, NavigationSkipped, Router } from '@angular/router';
import { filter } from 'rxjs';

/** Dismisses the mobile drawer once a link in it is followed, the already-active one included: the router skips
 *  that same-URL navigation rather than ending it. Call from an injection context. */
export function closeDrawerOnNavigation(open: Signal<boolean>, close: () => void): void {
  inject(Router)
    .events.pipe(
      filter((event) => event instanceof NavigationEnd || event instanceof NavigationSkipped),
      filter(() => open()),
      takeUntilDestroyed(),
    )
    .subscribe(close);
}
