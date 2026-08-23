// src/app/core/translated-title.strategy.ts
import { Injectable, inject } from '@angular/core';
import { ActivatedRouteSnapshot, RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { PageTitleService } from './page-title.service';

const DYNAMIC_TITLE_KEY = 'dynamicTitle';

/** Route data that hands the title to the routed component. */
export const DYNAMIC_TITLE = { [DYNAMIC_TITLE_KEY]: true } as const;

/**
 * Titles every page from its route. A route's `title` is a translation KEY, not
 * the finished string: PageTitleService resolves it and re-resolves it on a
 * language switch. A route without a title resets to the product name, so a
 * page can never keep the title of the page before it.
 */
@Injectable({ providedIn: 'root' })
export class TranslatedTitleStrategy extends TitleStrategy {
  private readonly pageTitle = inject(PageTitleService);

  override updateTitle(state: RouterStateSnapshot): void {
    // The reader names itself after the open article or the selected list, and
    // it changes both by query parameter — so every one of those changes comes
    // through here as a navigation. Resetting would blank the title between the
    // two writes; the component owns it from first render to last.
    if (ownsItsTitle(state.root)) return;

    const key = this.buildTitle(state);
    if (key === undefined) {
      this.pageTitle.reset();
      return;
    }

    this.pageTitle.useKey(key);
  }
}

function ownsItsTitle(root: ActivatedRouteSnapshot): boolean {
  for (let route: ActivatedRouteSnapshot | null = root; route !== null; route = route.firstChild) {
    if (route.data[DYNAMIC_TITLE_KEY] === true) return true;
  }
  return false;
}
