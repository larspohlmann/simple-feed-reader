import { Provider, Type } from '@angular/core';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { AUTOPLAY_REGION, AutoplayRegion } from '../../media/autoplay/autoplay-region';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope } from '../reading/reading-scope.service';
import { ArticleSelection } from '../selection-share/article-selection.service';
import { ARTICLE_PAGE_SOURCE, ArticlePageSource } from './article-page-source';

export interface ArticlePageProviders {
  readonly source: Type<ArticlePageSource>;
  readonly autoplayRegion: () => AutoplayRegion;
}

/** The services an article page reads, provided by the view so its projected parts reach them too. */
export function provideArticlePage(page: ArticlePageProviders): Provider[] {
  return [
    { provide: ARTICLE_PAGE_SOURCE, useExisting: page.source },
    ReadingScope,
    ArticleSelection,
    ArticleScrollRestore,
    ListAutoplayCoordinator,
    UnlockedVideoElements,
    { provide: AUTOPLAY_REGION, useFactory: page.autoplayRegion },
  ];
}

export function noAutoplayRegion(): AutoplayRegion {
  return () => undefined;
}
