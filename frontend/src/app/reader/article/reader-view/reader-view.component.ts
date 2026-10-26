import {
  Component,
  ElementRef,
  Injector,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  viewChild,
} from '@angular/core';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { ScrollProgressRail } from '../../../shared/progress-rail/scroll-progress-rail';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { EntrySourceComponent } from '../../entry/entry-source/entry-source.component';
import { LoadingOverlayComponent } from '../../../shared/loading-overlay/loading-overlay.component';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { PaywallNoticeComponent } from '../paywall-notice/paywall-notice.component';
import { EntryCommentsComponent } from '../entry-comments/entry-comments.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { ReaderModeService } from '../content/reader-mode.service';
import { ArticleSource } from '../content/article-source.service';
import { LanguageService } from '../../../core/i18n/language.service';
import { LayoutService } from '../../layout.service';
import { ArticleScrollRestore } from '../reading/article-scroll-restore.service';
import { ReadingScope, type TrailingPart } from '../reading/reading-scope.service';
import { prefersReducedMotion } from '../../../core/reduced-motion';
import { TocEntry, collectToc } from '../reading/reading-toc';
import { ReaderTocComponent } from '../reader-toc/reader-toc.component';
import { ListenRowComponent } from '../listen-row/listen-row.component';
import { LinkCardsComponent } from '../link-cards/link-cards.component';
import { ThreadRepliesComponent } from '../thread-replies/thread-replies.component';
import { PostMediaComponent } from '../../media/post-media/post-media.component';
import { entryMediumRatio } from '../../entry-medium';
import { QuotedPostComponent } from '../../quoted-post/quoted-post.component';
import { SocialCountsComponent } from '../social-counts/social-counts.component';
import { SocialCountsService } from '../social-counts/social-counts.service';
import { CommentsService } from '../entry-comments/comments.service';
import { CommentsForecast } from '../entry-comments/comments-forecast';
import { ArticleContentComponent } from '../article-content/article-content.component';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';
import { AUTOPLAY_REGION, AutoplayRegion } from '../../media/autoplay/autoplay-region';
import { ArticleLayerComponent } from '../article-layer/article-layer.component';
import { relativeTime } from '../../format';
import { estimateReadingMinutes } from '../decorators/reading-time';
import { AudioPlayerService } from '../../audio-player.service';
import { EntryAudio } from '../../audio/entry-audio';
import { entryDisplayTitle, isPost } from '../../entry/entry-text';

import { ShareButtonComponent } from '../share/share-button.component';
@Component({
  selector: 'app-reader-view',
  imports: [
    ProxiedImageDirective,
    IconComponent,
    ListActionDirective,
    FlagToggleDirective,
    FaviconComponent,
    EntrySourceComponent,
    LoadingOverlayComponent,
    EntryPillsComponent,
    EntryActionsComponent,
    TranslocoPipe,
    PaywallNoticeComponent,
    WarningBoxComponent,
    ErrorBannerComponent,
    EntryCommentsComponent,
    ReaderTocComponent,
    LinkCardsComponent,
    ThreadRepliesComponent,
    ListenRowComponent,
    QuotedPostComponent,
    PostMediaComponent,
    SocialCountsComponent,
    ArticleContentComponent,
    ArticleLayerComponent,
    ShareButtonComponent,
  ],
  providers: [
    ArticleScrollRestore,
    ArticleSource,
    ReadingScope,
    ListAutoplayCoordinator,
    UnlockedVideoElements,
    { provide: AUTOPLAY_REGION, useFactory: theArticleComments },
  ],
  templateUrl: './reader-view.component.html',
  styleUrl: './reader-view.component.scss',
})
export class ReaderViewComponent {
  protected readonly entryDisplayTitle = entryDisplayTitle;
  protected readonly isPost = isPost;
  protected readonly ratio = entryMediumRatio;

  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly fullscreen = input(false);
  protected readonly actions = inject(EntryActionHandler);

  readonly openOriginal = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly articleContent = viewChild(ArticleContentComponent);
  private readonly content = computed(() => this.articleContent()?.element);
  private readonly commentsSection = viewChild(EntryCommentsComponent, {
    read: ElementRef<HTMLElement>,
  });
  private readonly postMedia = viewChild(PostMediaComponent, { read: ElementRef<HTMLElement> });
  private readonly quotedPost = viewChild(QuotedPostComponent, {
    read: ElementRef<HTMLElement>,
  });
  private readonly linkCards = viewChild(LinkCardsComponent, { read: ElementRef<HTMLElement> });
  private readonly threadReplies = viewChild(ThreadRepliesComponent, {
    read: ElementRef<HTMLElement>,
  });
  private readonly socialCountsRow = viewChild(SocialCountsComponent);
  private readonly socialCounts = computed(() => this.socialCountsRow()?.host);
  private readonly comments = inject(CommentsService);
  private readonly commentsForecast = new CommentsForecast(
    this.entry,
    this.comments,
    inject(SocialCountsService),
  );
  protected readonly commentCount = computed(() => {
    const entry = this.entry();
    if (!entry?.comments) return null;
    const state = this.comments.state(entry.id)();
    return state.status === 'ok' ? state.comments.length + state.omitted : null;
  });
  private readonly trailingParts: readonly TrailingPart[] = [
    { query: this.postMedia, joinsFocus: false },
    { query: this.quotedPost, joinsFocus: false },
    { query: this.linkCards, joinsFocus: false },
    { query: this.threadReplies, joinsFocus: false },
    { query: this.socialCounts, joinsFocus: false },
    {
      query: this.commentsSection,
      joinsFocus: true,
      expectedHeight: this.commentsForecast.expectedHeight,
    },
  ];
  private readonly titleHeading = viewChild<ElementRef<HTMLElement>>('titleHeading');
  private readonly layer = viewChild(ArticleLayerComponent);
  private readonly scroller = computed(() => this.layer()?.scroller());
  protected readonly readerMode = inject(ReaderModeService);
  private readonly language = inject(LanguageService);
  private readonly screen = inject(LayoutService);
  private readonly reduceMotion = prefersReducedMotion();
  private readonly restore = inject(ArticleScrollRestore);
  protected readonly source = inject(ArticleSource);
  protected readonly scope = inject(ReadingScope);
  private readonly autoplay = inject(ListAutoplayCoordinator);
  protected readonly progressRail = new ScrollProgressRail({
    isWide: this.screen.isWide,
    contentBottom: this.scope.readingEnd,
    layout: this.scope.readingEnd,
  });

  private readonly player = inject(AudioPlayerService);
  /** The entry's first playable audio enclosure, surfaced as a listen control
   *  above the article; its attachment is null when the feed declared none (#915). */
  protected readonly audio = new EntryAudio(this.entry, this.player, this.source.chapters);

  /** Summary plus player is the whole of an audio-first entry whose page holds no article (#1428). */
  protected readonly fallbackNoticeShown = computed(
    () =>
      this.source.failed() &&
      this.mode() === 'original' &&
      this.source.failureReason() !== 'player_page' &&
      !(this.audio.attachment() && this.source.pageHoldsNoArticle()),
  );

  protected readonly fallbackMessageKey = computed(
    () => `reader.fallback.${this.source.failureReason() ?? 'transport'}`,
  );

  // The open entry's reference changes on every optimistic flag update, but its
  // id doesn't. Tracking the loaded id lets the load effect ignore those churns
  // — no re-fetch, and the Reader/Original toggle survives an in-reader action.
  private loadedId: number | null = null;

  // Alias the shared mode signal so the template and computeds read it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;

  // Table of contents, built from the rendered article headings. `tocOpen` lives
  // here, not in the TOC, so it survives the reader/original swap.
  readonly toc = signal<TocEntry[]>([]);
  readonly tocOpen = signal(false);

  /** Estimated minutes to read the displayed text; null hides the meta chip. */
  readonly readingMinutes = computed(() => estimateReadingMinutes(this.source.displayHtml()));

  constructor() {
    this.source.connect(this.entry);
    this.restore.connect(this.scroller);

    effect(() => {
      const entry = this.entry();
      const id = entry?.id ?? null;
      // Only react to a genuine entry change — not to a same-entry reference
      // churn from an optimistic flag update (which must not cancel an in-flight
      // load, re-fetch, or reset the mode toggle).
      if (id === this.loadedId) return;
      this.loadedId = id;
      // Before the source opens: a synchronous load calls enableToggle().
      this.readerMode.reset();
      this.restore.arm(id);
      // This instance is reused: a new article starts with a fresh, collapsed TOC.
      this.toc.set([]);
      this.tocOpen.set(false);
      this.autoplay.soundChanged(false);
      this.source.open(entry);
    });

    effect(() => this.describeQueuedChapters());

    this.scope.connect({
      scroller: this.scroller,
      content: this.content,
      trailing: this.trailingParts,
    });

    this.scope.observeResizes();
  }

  /** An episode queued from a list row, where no chapters were loaded, gets them from the open article (#1810). */
  private describeQueuedChapters(): void {
    const chapters = this.source.chapters();
    const url = this.audio.attachment()?.url;
    if (chapters.length === 0 || !url || !this.player.isQueued(url)) return;
    this.player.describeChapters(url, chapters);
  }

  protected onDecorated(host: HTMLElement): void {
    this.toc.set(collectToc(host));
    this.scope.refresh();
    // Runs on the original render and again when the reader content swaps in.
    this.restore.reseat(() => this.entry()?.id);
  }

  protected onScroll(scrollTop: number): void {
    if (!this.layer()?.leaving()) this.restore.remember(this.entry()?.id, scrollTop);
  }

  scrollToTop(): void {
    this.restore.abort(); // don't let a restore fight the jump
    this.layer()?.scrollToTop(this.titleHeading()?.nativeElement);
  }

  scrollToHeading(id: string): void {
    this.scrollToElement(
      this.content()?.nativeElement.querySelector<HTMLElement>(`#${CSS.escape(id)}`),
    );
  }

  protected scrollToComments(): void {
    this.scrollToElement(this.commentsSection()?.nativeElement);
  }

  /** Scroll the reading pane to an element, clearing the chrome that covers the scroller. */
  private scrollToElement(target: HTMLElement | null | undefined): void {
    const scroller = this.scroller();
    if (!scroller || !target) return;
    this.restore.abort(); // a jump takes over from any in-flight restore
    const top =
      target.getBoundingClientRect().top -
      scroller.getBoundingClientRect().top +
      scroller.scrollTop;
    const covered = parseFloat(getComputedStyle(scroller).scrollPaddingTop) || 0;
    scroller.scrollTo({
      top: Math.max(0, top - covered),
      behavior: this.reduceMotion ? 'auto' : 'smooth',
    });
  }

  commentsRegion(): Element | undefined {
    return this.commentsSection()?.nativeElement;
  }

  protected reloadSocialCounts(): void {
    this.socialCountsRow()?.reload();
  }

  /** The inline error's retry action — refetches the open entry's body. */
  retryBody(): void {
    const entry = this.entry();
    if (entry) this.source.retryBody(entry.id);
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  /** Drop the open article from the browser cache and refetch it. */
  refreshArticle(): void {
    const entry = this.entry();
    if (!entry) return;
    this.source.reload(entry.id);
  }

  when(entry: EntryDto): string {
    return relativeTime(entry.publishedAt ?? entry.createdAt, this.language.lang());
  }
}

/** Resolved on first use: the coordinator is built while the view is still constructing. */
function theArticleComments(): AutoplayRegion {
  const injector = inject(Injector);
  return () => injector.get(ReaderViewComponent).commentsRegion();
}
