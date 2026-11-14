import {
  Component,
  ElementRef,
  OnChanges,
  computed,
  effect,
  inject,
  input,
  output,
  untracked,
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
import { EntryShareRecorder } from '../share/entry-share-recorder';
import { SHARE_RECORDER } from '../share/share-recorder';
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
import { HandedAutoplayRegion, handedAutoplayRegion } from '../../media/autoplay/autoplay-region';
import { entryRelativeTime } from '../../format';
import { EntryDiscussionLinkComponent } from '../entry-comments/entry-discussion-link.component';
import { EntryCornerFlagsComponent } from '../../entry/entry-corner-flags/entry-corner-flags.component';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';
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
    VotePillComponent,
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
    { provide: SHARE_RECORDER, useClass: EntryShareRecorder },
    EntryArticlePage,
    HandedAutoplayRegion,
    provideArticlePage({ source: EntryArticlePage, autoplayRegion: handedAutoplayRegion }),
  ],
  templateUrl: './reader-view.component.html',
  styleUrl: './reader-view.component.scss',
})
export class ReaderViewComponent implements OnChanges {
  protected readonly ratio = entryMediumRatio;

  readonly entry = input.required<EntryDto>();
  readonly tags = input<SubscriptionTagDto[]>([]);
  readonly fullscreen = input(false);
  protected readonly actions = inject(EntryActionHandler);

  readonly openOriginal = output<void>();
  readonly back = output<void>();

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

  readonly mode = this.readerMode.mode;
  private readonly openId = computed(() => this.entry().id);

  constructor() {
    inject(HandedAutoplayRegion).handOver(() => this.commentsSection()?.nativeElement);
    effect(() => {
      this.openId();
      untracked(() => this.autoplay.soundChanged(false));
    });
  }

  ngOnChanges(): void {
    this.source.show(this.entry());
  }

  protected scrollToComments(): void {
    this.page()?.scrollToElement(this.commentsSection()?.nativeElement);
  }

  protected reloadSocialCounts(): void {
    this.socialCountsRow()?.reload();
  }

  /** The inline error's retry action — refetches the open entry's body. */
  retryBody(): void {
    this.source.retryBody();
  }

  toggleMode(): void {
    this.readerMode.toggle();
  }

  when(entry: EntryDto): string {
    return entryRelativeTime(entry, this.language.lang());
  }
}
