import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { SubscriptionRows } from '../../state/subscription-rows';
import { sourceNameOf } from '../../entry/entry-source/source-name';
import { feedAvatarUrl, networkOf } from '../../state/social-sections';
import { SocialNetworkName } from '../../../core/social-network';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { StoryArticleDto } from '../trending.models';

/** The sharers a row shows before its "+N" pill. */
export const SHOWN_SHARERS = 3;

interface Sharer {
  share: EntryDto;
  name: string;
  network: SocialNetworkName | null;
  avatarUrl: string | null;
}

/** One account per network: the poster's profile, else the feed that posted it, else a profile-less poster's name. */
function accountKey(share: EntryDto, sharer: Sharer): string {
  const account = share.poster
    ? (share.poster.url ?? `name:${sharer.name}`)
    : `feed:${share.subscriptionId}`;
  return `${sharer.network ?? ''} ${account}`;
}

/** A Trending article's sharers as pills, each opening the post that shared it (#2229). */
@Component({
  selector: 'app-story-sharers',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, FaviconComponent, NetworkLogoComponent],
  templateUrl: './story-sharers.component.html',
  styleUrl: './story-sharers.component.scss',
})
export class StorySharersComponent {
  readonly article = input.required<StoryArticleDto>();

  private readonly actions = inject(EntryActionHandler);
  private readonly rows = inject(SubscriptionRows);

  /** One per account, at its newest post: an account sharing the article in two feeds is one sharer. */
  private readonly all = computed(() => {
    const byAccount = new Map<string, Sharer>();
    for (const share of this.article().shares) {
      const sharer = this.sharerOf(share);
      const account = accountKey(share, sharer);
      if (!byAccount.has(account)) byAccount.set(account, sharer);
    }
    return [...byAccount.values()];
  });
  private readonly expanded = signal(false);
  protected readonly shown = computed(() =>
    this.expanded() ? this.all() : this.all().slice(0, SHOWN_SHARERS),
  );
  protected readonly hiddenCount = computed(() => this.all().length - this.shown().length);

  private sharerOf(share: EntryDto): Sharer {
    const feed = this.rows.find(share.subscriptionId);
    return {
      share,
      name: sourceNameOf(share, this.rows),
      network: share.social?.network ?? (feed ? networkOf(feed) : null),
      avatarUrl: share.poster?.imageUrl ?? feedAvatarUrl(feed),
    };
  }

  showAll(event: Event): void {
    event.stopPropagation();
    this.expanded.set(true);
  }

  open(share: EntryDto, event: Event): void {
    event.stopPropagation();
    this.actions.open(share);
  }
}
