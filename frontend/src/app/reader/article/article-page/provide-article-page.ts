import { Provider, Type } from '@angular/core';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { AUTOPLAY_REGION, AutoplayRegion } from '../../media/autoplay/autoplay-region';
import { ARTICLE_PAGE_SOURCE, ArticlePageSource } from './article-page-source';

export interface ArticlePageProviders {
  readonly source: Type<ArticlePageSource>;
  readonly autoplayRegion: () => AutoplayRegion;
}

/** The services a view's projected parts share with its article page. */
export function provideArticlePage(page: ArticlePageProviders): Provider[] {
  return [
    { provide: ARTICLE_PAGE_SOURCE, useExisting: page.source },
    ListAutoplayCoordinator,
    UnlockedVideoElements,
    { provide: AUTOPLAY_REGION, useFactory: page.autoplayRegion },
  ];
}

export function noAutoplayRegion(): AutoplayRegion {
  return () => undefined;
}
