import { Injectable, effect, inject } from '@angular/core';
import { PageTitleService } from '../../core/i18n/page-title.service';
import { ReaderRouteState } from './reader-route-state.service';
import { entryDisplayTitle } from '../entry/entry-text';
import { LinkArticleSource } from '../article/link-reader/link-article-source.service';
import { injectHeadingOnScreen } from './list-cover';

/** Names the tab after the open link's page, else the open article, else the list on screen (#2329).
 *  The reader route carries no title of its own, so this is the only writer
 *  while the reader is on screen. Injected for its effect. */
@Injectable()
export class ReaderTabTitle {
  private readonly pageTitle = inject(PageTitleService);
  private readonly heading = injectHeadingOnScreen();
  private readonly openEntry = inject(ReaderRouteState).openEntry;
  private readonly linkTitle = inject(LinkArticleSource).title;

  constructor() {
    effect(() => {
      const linkTitle = this.linkTitle();
      if (linkTitle !== '') {
        this.pageTitle.useText(linkTitle);
        return;
      }
      const entry = this.openEntry();
      if (entry !== null) {
        this.pageTitle.useText(entryDisplayTitle(entry));
        return;
      }
      const heading = this.heading();
      this.pageTitle.useText(heading.tabTitle(), heading.titleCount().value);
    });
  }
}
