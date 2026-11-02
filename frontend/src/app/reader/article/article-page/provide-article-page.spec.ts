import { Injectable, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AUTOPLAY_REGION } from '../../media/autoplay/autoplay-region';
import { ARTICLE_PAGE_SOURCE, ArticlePageSource } from './article-page-source';
import { noAutoplayRegion, provideArticlePage } from './provide-article-page';

@Injectable()
class FakePageSource implements ArticlePageSource {
  readonly key = signal<string | undefined>('link:https://news.test/a');
  readonly url = signal<string | null>(null);
  readonly title = signal('');
  readonly shareTitle = signal('');
  readonly titleHidden = signal(false);
  readonly nameplate = signal('');
  readonly favicon = signal(null);
  readonly body = signal(null);
  readonly bodyAudio = signal(null);
  readonly lead = signal(null);
  readonly paywall = signal(null);
  readonly loading = signal(false);
  readonly canRefresh = signal(false);
  readonly failure = signal(null);
  readonly socialPost = signal(false);
  readonly readingAids = false;
  readonly dropLead = jest.fn();
  readonly reload = jest.fn();
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
