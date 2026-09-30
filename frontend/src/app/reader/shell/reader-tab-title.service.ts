import { Injectable, effect, inject } from '@angular/core';
import { PageTitleService } from '../../core/i18n/page-title.service';
import { ReaderRouteState } from './reader-route-state.service';
import { ListHeading } from './list-heading.service';

/** Names the tab after the open article, or after the list when none is open.
 *  The reader route carries no title of its own, so this is the only writer
 *  while the reader is on screen. Injected for its effect. */
@Injectable()
export class ReaderTabTitle {
  private readonly pageTitle = inject(PageTitleService);
  private readonly heading = inject(ListHeading);
  private readonly openEntry = inject(ReaderRouteState).openEntry;

  constructor() {
    effect(() => {
      const entry = this.openEntry();
      if (entry !== null) {
        this.pageTitle.useText(entry.title);
        return;
      }
      this.pageTitle.useText(this.heading.title(), this.heading.titleCount().value);
    });
  }
}
