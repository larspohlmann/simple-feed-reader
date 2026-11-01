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
import { ResponseActions } from '../composer/response-actions.service';
import { CommentsService } from './comments.service';
import { relativeTime } from '../../format';
import { EntryDto } from '../../models';
import { EntryCommentDto, EntryCommentPostDto } from '../../entry-comment';
import { ResponseKind, likeableSocial } from '../../entry-social';
import { mediumRatio } from '../../entry-medium';
import { PostMediaComponent } from '../../media/post-media/post-media.component';
import { QuotedPostComponent } from '../../quoted-post/quoted-post.component';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { SocialCountsRowComponent } from '../social-counts/social-counts-row.component';
import { ToastService } from '../../../shared/toast/toast.service';
import { textOf } from '../../../shared/text-of';
import { problemMessage } from '../../../core/problem';

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
  ],
  templateUrl: './entry-comments.component.html',
  styleUrl: './entry-comments.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCommentsComponent {
  readonly entry = input.required<CommentsEntry>();
  readonly refreshed = output<void>();

  private readonly service = inject(CommentsService);
  private readonly toast = inject(ToastService);
  private readonly responses = inject(ResponseActions);
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
    this.service.toggleLike(this.entryId(), post, (problem) =>
      this.toast.show({ message: problemMessage(problem) }),
    );
  }

  toggleRepost(post: EntryCommentPostDto): void {
    this.service.toggleRepost(this.entryId(), post, (problem) =>
      this.toast.show({ message: problemMessage(problem) }),
    );
  }

  respond(kind: ResponseKind, comment: EntryCommentDto, post: EntryCommentPostDto): void {
    const network = this.entry().social?.network;
    if (network === undefined) return;
    this.responses.respond({
      kind,
      entryId: this.entryId(),
      network,
      address: post.address,
      authorName: comment.author,
      authorHandle: comment.authorHandle,
      excerpt: textOf(comment.html),
      card: null,
    });
  }

  deleteReply(network: SocialNetworkName, comment: EntryCommentDto): void {
    this.responses.delete(this.entryId(), network, comment);
  }

  when(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  indentOf(comment: EntryCommentDto): number {
    return Math.min(comment.depth, DEEPEST_INDENT);
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
