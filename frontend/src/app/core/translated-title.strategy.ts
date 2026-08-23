// src/app/core/translated-title.strategy.ts
import { Injectable, inject } from '@angular/core';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { PageTitleService } from './page-title.service';

/** The title a route declares when its component names the page itself. Not a
 *  translation key: it is never shown, it only says who does the naming. */
export const DYNAMIC_TITLE = 'title.ownedByThePage';

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
    const key = this.buildTitle(state);

    // The reader names itself after the open article or the selected list, and
    // it changes both by query parameter — so every one of those changes comes
    // through here as a navigation. Writing would blank the title between the
    // reader's two writes; the component owns it from first render to last.
    if (key === DYNAMIC_TITLE) return;

    if (key === undefined) {
      this.pageTitle.reset();
      return;
    }

    this.pageTitle.useKey(key);
  }
}
