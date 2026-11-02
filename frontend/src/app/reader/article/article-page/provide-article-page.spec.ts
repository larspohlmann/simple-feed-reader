import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AUTOPLAY_REGION } from '../../media/autoplay/autoplay-region';
import { StubArticlePageSource } from '../../../../testing/article-page-source-testing';
import { ARTICLE_PAGE_SOURCE } from './article-page-source';
import { noAutoplayRegion, provideArticlePage } from './provide-article-page';

@Injectable()
class FakePageSource extends StubArticlePageSource {
  constructor() {
    super();
  }
}

describe('provideArticlePage', () => {
  it('hands the page the view’s own source instance', () => {
    TestBed.configureTestingModule({
      providers: [
        FakePageSource,
        provideArticlePage({ source: FakePageSource, autoplayRegion: noAutoplayRegion }),
      ],
    });

    expect(TestBed.inject(ARTICLE_PAGE_SOURCE)).toBe(TestBed.inject(FakePageSource));
  });

  it('builds the autoplay region from the view’s factory', () => {
    const region = () => undefined;
    TestBed.configureTestingModule({
      providers: [
        FakePageSource,
        provideArticlePage({ source: FakePageSource, autoplayRegion: () => region }),
      ],
    });

    expect(TestBed.inject(AUTOPLAY_REGION)).toBe(region);
  });
});
