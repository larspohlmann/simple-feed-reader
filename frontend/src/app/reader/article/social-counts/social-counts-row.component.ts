import {
  ChangeDetectionStrategy,
  Component,
  OutputEmitterRef,
  computed,
  inject,
  input,
  output,
} from '@angular/core';
import { Observable } from 'rxjs';
import { PendingAction } from '../../../core/rx/pending-action';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { PendingDotsComponent } from '../../../shared/pending-dots/pending-dots.component';
import { CompactCount } from '../../../core/i18n/compact-count';
import { pluralKey } from '../../../core/i18n/plural-key';
import { SocialNetworkName } from '../../../core/social-network';
import { LikeTarget, PostActionsView, SocialCountsDto, formatLikeKey } from '../../entry-social';
import { RepostButtonComponent } from './repost-button.component';
import { VotePillComponent } from '../../vote-pill/vote-pill.component';
import { VoteValue } from '../../vote-pill/vote';

type CountKind = 'likes' | 'reposts' | 'boosts' | 'replies' | 'quotes';

interface ShownCount {
  kind: CountKind;
  icon: string;
  count: number;
}

/** A count the reader can answer from: the reply or the quote pill, dimmed with its reason where refused. */
interface ResponsePill {
  kind: 'reply' | 'quote';
  countKind: CountKind;
  icon: string;
  label: string;
  count: number | null;
  allowed: boolean;
  requested: OutputEmitterRef<void>;
}

/**
 * A post's counters with its repost, reply, quote and like buttons; `liked` and `actions` are null when the reader
 * cannot act on the post, which shows plain counts instead.
 */
@Component({
  selector: 'app-social-counts-row',
  imports: [
    IconComponent,
    PendingDotsComponent,
    TranslocoPipe,
    RepostButtonComponent,
    VotePillComponent,
  ],
  templateUrl: './social-counts-row.component.html',
  styleUrl: './social-counts-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialCountsRowComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly counts = input.required<SocialCountsDto | null>();
  readonly liked = input.required<boolean | null>();
  readonly actions = input<PostActionsView | null>(null);
  readonly replyLabel = input<'reader.addComment' | 'reader.replyToComment'>('reader.addComment');
  /** Lemmy's score with the reader's vote; it stands where the other networks show their like. */
  readonly score = input<number | null>(null);
  readonly myVote = input<VoteValue | null>(null);
  readonly vote = input<((to: VoteValue) => Observable<unknown>) | null>(null);
  readonly like = input<(() => Observable<unknown>) | null>(null);
  readonly likeTarget = input<LikeTarget | null>(null);
  readonly repostToggled = output<void>();
  readonly replyRequested = output<void>();
  readonly quoteRequested = output<void>();

  private readonly compactCount = inject(CompactCount);
  protected readonly liking = new PendingAction<true>();

  protected readonly shown = computed(
    () =>
      this.score() !== null ||
      this.liked() !== null ||
      this.actions() !== null ||
      this.counts() !== null,
  );
  protected readonly shares = computed(() => this.network() !== 'lemmy');
  protected readonly responsePills = computed<ResponsePill[]>(() => {
    const counts = this.counts();
    const actions = this.actions();
    if (actions === null) return [];
    const pills: ResponsePill[] = [
      {
        kind: 'reply',
        countKind: 'replies',
        icon: 'reply',
        label: this.replyLabel(),
        count: counts?.replies ?? null,
        allowed: actions.canReply,
        requested: this.replyRequested,
      },
      {
        kind: 'quote',
        countKind: 'quotes',
        icon: 'format_quote',
        label: 'reader.actionLabel.quote',
        count: counts?.quotes ?? null,
        allowed: actions.canQuote,
        requested: this.quoteRequested,
      },
    ];
    return this.shares() ? pills : pills.slice(0, 1);
  });
  protected readonly likeKey = computed(() =>
    formatLikeKey(this.liked() === true, this.likeTarget() ?? this.network()),
  );
  protected readonly shownCounts = computed<ShownCount[]>(() => {
    const counts = this.counts();
    if (!counts) return [];
    const shown: ShownCount[] = [];
    if (this.actions() === null) {
      shown.push({ kind: this.repostKind(), icon: 'repeat', count: counts.reposts });
      shown.push({ kind: 'replies', icon: 'chat_bubble', count: counts.replies });
      if (counts.quotes !== null) {
        shown.push({ kind: 'quotes', icon: 'format_quote', count: counts.quotes });
      }
    }
    if (this.liked() === null) shown.push({ kind: 'likes', icon: 'thumb_up', count: counts.likes });
    return shown;
  });

  protected readonly repostKind = computed<CountKind>(() =>
    this.network() === 'mastodon' ? 'boosts' : 'reposts',
  );
  protected hintKey(pill: ResponsePill): string {
    return `reader.${pill.kind}${pill.allowed ? '' : 'Forbidden'}.${this.network()}`;
  }

  protected format(count: number): string {
    return this.compactCount.format(count);
  }

  protected labelKey(kind: CountKind, count: number): string {
    return pluralKey(`reader.socialCounts.${kind}`, count);
  }

  protected toggleLike(): void {
    const like = this.like();
    if (like !== null) this.liking.run(true, like);
  }
}
