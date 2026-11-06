import { Injectable, computed, effect, inject } from '@angular/core';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';
import { ListenLauncher } from '../../audio/listen-launcher';
import { embedTrackOrigin } from '../../audio/embed-track';
import { entryDisplayTitle, isPost } from '../../entry/entry-text';
import { ArticleSource } from '../content/article-source.service';
import { ReaderModeService } from '../content/reader-mode.service';
import { entryPageKey } from '../reading/article-page-key';
import {
  ArticlePageBody,
  ArticlePageBodyAudio,
  ArticlePageFailure,
  ArticlePageFavicon,
  ArticlePagePaywall,
  ArticlePageSource,
} from '../article-page/article-page-source';

@Injectable()
export class EntryArticlePage implements ArticlePageSource {
  private readonly article = inject(ArticleSource);
  private readonly readerMode = inject(ReaderModeService);
  private readonly entry = this.article.entry;

  constructor() {
    effect(() => this.audio.shareChapters());
  }

  /** The entry's first playable audio enclosure, surfaced as a listen control
   *  above the article; its attachment is null when the feed declared none (#915). */
  readonly audio = new EntryAudio(
    { entry: () => this.entry(), chapters: this.article.chapters },
    inject(AudioPlayerService),
    inject(ListenLauncher),
  );

  readonly loading = this.article.loading;
  readonly lead = this.article.hero;

  readonly key = computed(() => entryPageKey(this.entry().id));
  readonly url = computed(() => this.entry().url);
  readonly openUrl = computed(() => this.entry().social?.openUrl ?? this.entry().url);
  readonly title = computed(() => entryDisplayTitle(this.entry()));
  readonly shareTitle = computed(() => this.entry().title);
  readonly titleHidden = computed(() => {
    const entry = this.entry();
    return isPost(entry) || !entry.title;
  });
  readonly nameplate = this.title;
  readonly favicon = computed<ArticlePageFavicon>(() => {
    const entry = this.entry();
    return { url: entry.faviconUrl, avatarUrl: entry.poster?.imageUrl ?? null };
  });
  readonly body = computed<ArticlePageBody>(() => ({
    html: this.article.displayHtml(),
    pageUrl: this.entry().url,
  }));
  readonly bodyAudio = computed<ArticlePageBodyAudio>(() => ({
    enclosureUrl: this.audio.attachment()?.url ?? null,
    chapters: this.article.chapters(),
    track: this.audio.track(),
    origin: embedTrackOrigin(this.entry()),
  }));
  readonly paywall = computed<ArticlePagePaywall | null>(() =>
    this.article.paywalled() ? { url: this.article.paywallUrl() } : null,
  );
  readonly canRefresh = computed(() => this.readerMode.mode() === 'reader');
  readonly socialPost = computed(() => this.entry().social != null);

  /** Summary plus player is the whole of an audio-first entry whose page holds no article (#1428). */
  private readonly fallbackShown = computed(
    () =>
      this.article.failed() &&
      this.readerMode.mode() === 'original' &&
      this.article.failureReason() !== 'player_page' &&
      !(this.audio.attachment() && this.article.pageHoldsNoArticle()),
  );

  readonly failure = computed<ArticlePageFailure | null>(() => {
    if (!this.fallbackShown()) return null;
    const reason = this.article.failureReason();
    return {
      messageKey: `reader.fallback.${reason ?? 'transport'}`,
      quiet: reason === 'empty',
      detail: this.article.errorDetail(),
    };
  });

  dropLead(): void {
    this.article.dropHero();
  }

  /** Drop the open article from the browser cache and refetch it. */
  reload(): void {
    this.article.reload();
  }
}
