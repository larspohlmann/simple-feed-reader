import {
  Component,
  ElementRef,
  Injector,
  computed,
  effect,
  inject,
  input,
  output,
  viewChild,
} from '@angular/core';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { EntrySourceComponent } from '../../entry/entry-source/entry-source.component';
import { EntryPillsComponent } from '../../entry/entry-pills/entry-pills.component';
import { EntryActionsComponent } from '../../entry/entry-actions/entry-actions.component';
import { EntryCommentsComponent } from '../entry-comments/entry-comments.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { ReaderModeService } from '../content/reader-mode.service';
import { ArticleSource } from '../content/article-source.service';
import { LanguageService } from '../../../core/i18n/language.service';
import { type TrailingPart } from '../reading/reading-scope.service';
import { EpisodePanelsComponent } from '../episode-panels/episode-panels.component';
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
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { AutoplayRegion } from '../../media/autoplay/autoplay-region';
import { relativeTime } from '../../format';
import { EntryDiscussionLinkComponent } from '../entry-comments/entry-discussion-link.component';
import { EntryCornerFlagsComponent } from '../../entry/entry-corner-flags/entry-corner-flags.component';
import { ArticlePageComponent } from '../article-page/article-page.component';
import { ArticlePageSlotDirective } from '../article-page/article-page-slot.directive';
import { provideArticlePage } from '../article-page/provide-article-page';
import { EntryArticlePage } from './entry-article-page';

@Component({
  selector: 'app-reader-view',
  imports: [
    IconComponent,
    ListActionDirective,
    FlagToggleDirective,
    EntrySourceComponent,
    EntryPillsComponent,
    EntryActionsComponent,
    TranslocoPipe,
    ErrorBannerComponent,
    EntryCommentsComponent,
    EpisodePanelsComponent,
    LinkCardsComponent,
    ThreadRepliesComponent,
    ListenRowComponent,
    QuotedPostComponent,
    PostMediaComponent,
    SocialCountsComponent,
    EntryCornerFlagsComponent,
    EntryDiscussionLinkComponent,
    ArticlePageComponent,
    ArticlePageSlotDirective,
  ],
  providers: [
    ArticleSource,
    EntryArticlePage,
    provideArticlePage({ source: EntryArticlePage, autoplayRegion: theArticleComments }),
  ],
  templateUrl: './reader-view.component.html',
  styleUrl: './reader-view.component.scss',
})
export class ReaderViewComponent {
  protected readonly ratio = entryMediumRatio;

  readonly entry = input.required<EntryDto | null>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly fullscreen = input(false);
  protected readonly actions = inject(EntryActionHandler);

  readonly openOriginal = output<void>();
  // Semantic "back to list" output; not a DOM element's close event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly close = output<void>();

  private readonly page = viewChild(ArticlePageComponent);
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
  protected readonly trailingParts: readonly TrailingPart[] = [
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
  protected readonly readerMode = inject(ReaderModeService);
  private readonly language = inject(LanguageService);
  protected readonly source = inject(ArticleSource);
  private readonly entryPage = inject(EntryArticlePage);
  protected readonly audio = this.entryPage.audio;
  private readonly autoplay = inject(ListAutoplayCoordinator);

  // The open entry's reference changes on every optimistic flag update, but its
  // id doesn't. Tracking the loaded id lets the load effect ignore those churns
  // — no re-fetch, and the Reader/Original toggle survives an in-reader action.
  private loadedId: number | null = null;

  // Alias the shared mode signal so the template reads it directly;
  // writes go through the ReaderModeService lifecycle methods below.
  readonly mode = this.readerMode.mode;

  constructor() {
    this.source.connect(this.entry);
    this.entryPage.connect(this.entry);

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
      this.autoplay.soundChanged(false);
      this.source.open(entry);
    });

    effect(() => this.audio.shareChapters());
  }

  protected scrollToComments(): void {
    this.page()?.scrollToElement(this.commentsSection()?.nativeElement);
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

  when(entry: EntryDto): string {
    return relativeTime(entry.publishedAt ?? entry.createdAt, this.language.lang());
  }
}

/** Resolved on first use: the coordinator is built while the view is still constructing. */
function theArticleComments(): AutoplayRegion {
  const injector = inject(Injector);
  return () => injector.get(ReaderViewComponent).commentsRegion();
}
