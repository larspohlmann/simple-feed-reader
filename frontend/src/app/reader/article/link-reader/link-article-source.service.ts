import { Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { Observable } from 'rxjs';
import { HeroImageDto, ReaderContent } from '../../models';
import { SignedUrl } from '../../query/link-param';
import { ReaderRouteState } from '../../shell/reader-route-state.service';
import { ReaderContentService } from '../content/reader-content.service';
import { ReaderLoad } from '../content/reader-load';
import { linkPageKey } from '../reading/article-page-key';
import {
  ArticlePageBody,
  ArticlePageBodyAudio,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from '../article-page/article-page-source';

/** The page behind the link card the route opens; idle while none is open. */
@Injectable()
export class LinkArticleSource implements ArticlePageSource {
  private readonly reader = inject(ReaderContentService);
  private readonly routeState = inject(ReaderRouteState);
  private readonly load = new ReaderLoad();
  private readonly leadFailed = signal(false);

  private readonly card = this.routeState.openLinkCard;
  private readonly article = this.load.article;
  readonly loading = this.load.loading;

  readonly key = computed(() => linkPageKey(this.requireTarget().url));
  readonly title = computed(() => this.card()?.title || this.article()?.title || '');
  readonly shareTitle = this.title;
  readonly titleHidden = signal(false).asReadonly();
  readonly nameplate = computed(() => this.card()?.siteName ?? this.article()?.siteName ?? '');
  readonly favicon = signal<ArticlePageFavicon | null>(null).asReadonly();
  readonly url = computed(
    () => this.card()?.url ?? this.article()?.url ?? this.load.failure()?.url ?? null,
  );
  readonly body = computed<ArticlePageBody | null>(() => {
    const article = this.article();
    return article === null ? null : { html: article.contentHtml, pageUrl: article.url };
  });
  readonly bodyAudio = signal<ArticlePageBodyAudio | null>(null).asReadonly();
  readonly lead = computed<HeroImageDto | null>(() => {
    const image = this.card()?.imageUrl ?? null;
    if (!this.load.failed() || this.leadFailed() || image === null) return null;
    return { url: image, width: null, height: null };
  });
  readonly paywall = computed<ArticlePagePaywall | null>(() => {
    const article = this.article();
    return article?.paywalled ? { url: article.url } : null;
  });
  readonly canRefresh = computed(() => this.article() !== null || this.load.failed());
  readonly failure = computed<ArticlePageFailure | null>(() =>
    this.load.failed()
      ? { messageKey: 'reader.linkFailed', quiet: false, detail: this.load.errorDetail() }
      : null,
  );
  readonly socialPost = signal(false).asReadonly();

  constructor() {
    effect(() => {
      const target = this.routeState.linkTarget();
      untracked(() => (target === null ? this.load.idle() : this.run(this.reader.load(target))));
    });
  }

  dropLead(): void {
    this.leadFailed.set(true);
  }

  reload(): void {
    const target = this.routeState.linkTarget();
    if (target !== null) this.run(this.reader.reload(target));
  }

  /** Only the open link reader reads its page, and it is mounted only while a link is open. */
  private requireTarget(): SignedUrl {
    const target = this.routeState.linkTarget();
    if (target === null) throw new Error('LinkArticleSource: no link open');
    return target;
  }

  private run(source: Observable<ReaderContent>): void {
    this.leadFailed.set(false);
    this.load.run(source);
  }
}
