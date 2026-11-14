import {
  ChangeDetectionStrategy,
  Component,
  NgZone,
  computed,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { AvatarComponent } from '../../../shared/avatar/avatar.component';
import { LanguageService } from '../../../core/i18n/language.service';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { PostActions } from '../composer/post-actions.service';
import { CommentsService } from './comments.service';
import { relativeTime } from '../../format';
import { EntryDto } from '../../models';
import { EntryCommentDto, EntryCommentPostDto, EntryCommentVoteDto } from '../../entry-comment';
import { VoteValue } from '../../vote-pill/vote';
import { likeableSocial } from '../../entry-social';
import { mediumRatio } from '../../entry-medium';
import { PostMediaComponent } from '../../media/post-media/post-media.component';
import { QuotedPostComponent } from '../../quoted-post/quoted-post.component';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { CommentActionsComponent } from './comment-actions.component';
import { SocialCountsRowComponent } from '../social-counts/social-counts-row.component';

type CommentsEntry = Pick<EntryDto, 'id' | 'comments' | 'discussionUrl' | 'social'>;

const DEEPEST_INDENT = 5;

@Component({
  selector: 'app-entry-comments',
  imports: [
    NgTemplateOutlet,
    TranslocoPipe,
    IconComponent,
    IconButtonDirective,
    ListActionDirective,
    SkeletonComponent,
    WarningBoxComponent,
    AvatarComponent,
    PostMediaComponent,
    QuotedPostComponent,
    LinkCardComponent,
    SocialCountsRowComponent,
    CommentActionsComponent,
  ],
  templateUrl: './entry-comments.component.html',
  styleUrl: './entry-comments.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCommentsComponent {
  readonly entry = input.required<CommentsEntry>();
  readonly refreshed = output<void>();

  private readonly service = inject(CommentsService);
  private readonly responses = inject(PostActions);
  private readonly language = inject(LanguageService);
  private readonly zone = inject(NgZone);
  private readonly now = signal(Date.now());
  private readonly entryId = computed(() => this.entry().id);
  private readonly loadsOnOpen = computed(() => this.entry().comments === 'auto');

  readonly state = computed(() => this.service.state(this.entryId())());
  protected readonly likeable = computed(() => likeableSocial(this.entry()) !== null);
  readonly network = computed(() => {
    const social = this.entry().social;
    return social ? SOCIAL_NETWORK_NAMES[social.network] : null;
  });
  readonly retryInSeconds = computed(() => {
    const state = this.state();
    if (state.status !== 'throttled') return 0;
    return Math.max(0, Math.ceil((state.retryAt - this.now()) / 1000));
  });
  private readonly throttledUntil = computed(() => {
    const state = this.state();
    return state.status === 'throttled' ? state.retryAt : null;
  });

  constructor() {
    effect(() => {
      const id = this.entryId();
      if (this.loadsOnOpen()) untracked(() => this.service.load(id));
    });
    effect((onCleanup) => {
      const until = this.throttledUntil();
      if (until !== null) onCleanup(this.countDownTo(until));
    });
  }

  load(): void {
    this.service.load(this.entryId());
  }

  reload(): void {
    this.service.reload(this.entryId());
  }

  refresh(): void {
    this.service.reload(this.entryId());
    this.refreshed.emit();
  }

  toggleLike(post: EntryCommentPostDto): void {
    this.service.toggleLike(this.entryId(), post);
  }

  toggleRepost(post: EntryCommentPostDto): void {
    this.service.toggleRepost(this.entryId(), post);
  }

  reply(network: SocialNetworkName, post: EntryCommentPostDto): void {
    this.responses.reply(this.entryId(), network, post.address);
  }

  vote(comment: EntryCommentDto, to: VoteValue): void {
    this.service.vote(this.entryId(), comment, to);
  }

  replyOnLemmy(vote: EntryCommentVoteDto): void {
    this.responses.reply(this.entryId(), 'lemmy', vote.address);
  }

  quote(network: SocialNetworkName, post: EntryCommentPostDto): void {
    this.responses.quote(this.entryId(), network, post.address);
  }

  deleteReply(comment: EntryCommentDto): void {
    const social = likeableSocial(this.entry());
    if (social !== null) {
      this.responses.delete(this.entryId(), social.network, comment);
    }
  }

  when(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  indentOf(comment: EntryCommentDto): number {
    return Math.min(comment.depth, DEEPEST_INDENT);
  }

  canDelete(comment: EntryCommentDto): boolean {
    return this.likeable() && comment.post?.isOwn === true;
  }

  ratioOf(comment: EntryCommentDto): number {
    return mediumRatio(comment.media[0]);
  }

  // Outside the zone: an in-zone interval never lets whenStable() settle.
  private countDownTo(until: number): () => void {
    this.now.set(Date.now());
    const ticker = this.zone.runOutsideAngular(() =>
      setInterval(() => {
        this.now.set(Date.now());
        if (Date.now() >= until) clearInterval(ticker);
      }, 1000),
    );
    return () => clearInterval(ticker);
  }
}
