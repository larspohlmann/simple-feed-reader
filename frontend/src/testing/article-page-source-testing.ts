import { signal } from '@angular/core';
import {
  ArticlePageBody,
  ArticlePageBodyAudio,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from '../app/reader/article/article-page/article-page-source';
import { HeroImageDto } from '../app/reader/models';

interface StubArticlePageOverrides {
  readonly url?: string;
  readonly title?: string;
  readonly shareTitle?: string;
  readonly nameplate?: string;
  readonly body?: ArticlePageBody;
  readonly canRefresh?: boolean;
}

export class StubArticlePageSource implements ArticlePageSource {
  readonly key = signal('link:https://news.test/a');
  readonly url = signal<string | null>(null);
  readonly openUrl = signal<string | null>(null);
  readonly title = signal('');
  readonly shareTitle = signal('');
  readonly titleHidden = signal(false);
  readonly nameplate = signal('');
  readonly favicon = signal<ArticlePageFavicon | null>(null);
  readonly body = signal<ArticlePageBody | null>(null);
  readonly bodyAudio = signal<ArticlePageBodyAudio | null>(null);
  readonly lead = signal<HeroImageDto | null>(null);
  readonly paywall = signal<ArticlePagePaywall | null>(null);
  readonly loading = signal(false);
  readonly canRefresh = signal(false);
  readonly failure = signal<ArticlePageFailure | null>(null);
  readonly socialPost = signal(false);
  readonly dropLead = jest.fn();
  readonly reload = jest.fn();

  constructor(overrides: StubArticlePageOverrides = {}) {
    this.url.set(overrides.url ?? null);
    this.openUrl.set(overrides.url ?? null);
    this.title.set(overrides.title ?? '');
    this.shareTitle.set(overrides.shareTitle ?? '');
    this.nameplate.set(overrides.nameplate ?? '');
    this.body.set(overrides.body ?? null);
    this.canRefresh.set(overrides.canRefresh ?? false);
  }
}
