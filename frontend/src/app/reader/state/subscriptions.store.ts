import { HttpErrorResponse } from '@angular/common/http';
import {
  Injectable,
  WritableSignal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { Observable } from 'rxjs';
import { FeedGroupName } from '../../core/feed-group';
import { Problem, parseProblem } from '../../core/problem';
import { onIdentityChange } from '../../core/auth/session-identity';
import { unhealthyFeeds } from '../feeds/feed-health';
import { ReaderApi } from '../reader-api';
import { TagsStore } from './tags.store';
import { SocialAccountRefDto } from '../social-feed';
import { SubscriptionFlags } from '../subscription-bulk';
import { StaleGatedLoad } from './stale-gated-load';
import { SubscriptionRows } from './subscription-rows';
import { buildSocialSections, isEmptySavedFeed, isSocialFeed } from './social-sections';
import {
  EntryDto,
  SidebarTotalsDto,
  SubscriptionCountsResponse,
  SubscriptionDto,
  SubscriptionsResponse,
} from '../models';
import { NO_YOUR_STREAM_TALLY, createYourStreamSignal } from '../sidebar-totals';
import { TrendingUnopened } from '../trending/trending.models';
import { NO_TRENDING_UNOPENED, sameTrendingUnopened } from '../trending/trending-window';
import { TrendingWindowService } from '../trending/trending-window.service';
import {
  FeedGroupCounts,
  buildTagTree,
  tagNodesById,
  taggedSubscriptions,
  totalEntryCount,
  untaggedSubscriptions,
} from './subscription-derivations';
import {
  NO_NOTIFICATION_COUNTS,
  NotificationCounts,
  notificationCountsFrom,
  sameNotificationCounts,
  totalUnseen,
  unseenNetworks,
} from './notification-counts';
import {
  NO_UNREAD_TOTALS,
  UnreadTotals,
  sameUnreadTotals,
  unreadTotalsFrom,
} from './unread-totals';
import { MARK_NAMES, Mark, toCountField } from './marks';

@Injectable({ providedIn: 'root' })
export class SubscriptionsStore {
  private readonly api = inject(ReaderApi);
  private readonly tags = inject(TagsStore);
  private readonly rows = inject(SubscriptionRows);
  private readonly trendingWindow = inject(TrendingWindowService);
  /** Every title resolved for display. */
  readonly subscriptions = this.rows.all;
  /** The rows as the server sent them, which an optimistic edit patches. */
  readonly storedSubscriptions = this.rows.stored;
  private readonly markCounts = buildMarkCounts();
  readonly favoritesCount = this.markCounts.favorites.asReadonly();
  readonly keptCount = this.markCounts.kept.asReadonly();
  readonly sharedCount = this.markCounts.shared.asReadonly();
  readonly likedCount = this.markCounts.liked.asReadonly();
  readonly viewedCount = this.markCounts.viewed.asReadonly();
  /** Unopened Trending stories in the window the sidebar links to; only the server moves them (#2017). */
  readonly trendingUnopened = signal<TrendingUnopened>(NO_TRENDING_UNOPENED, {
    equal: sameTrendingUnopened,
  });
  readonly yourStream = createYourStreamSignal();
  readonly socialAccounts = signal<SocialAccountRefDto[]>([]);
  readonly lemmyHosts = signal<readonly string[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  /** True once a load has completed, successfully or not. `loading` cannot serve
   *  here: it is false BEFORE the first request too, which would let a redirect
   *  fire against an empty list the server has not answered on yet. */
  readonly resolved = signal(false);

  private readonly storedUnreadTotals = signal<UnreadTotals>(NO_UNREAD_TOTALS, {
    equal: sameUnreadTotals,
  });
  /** The server's per-scope unread totals: each badge counts its own scope. */
  readonly unreadTotals = this.storedUnreadTotals.asReadonly();

  private readonly storedNotificationCounts = signal<NotificationCounts>(NO_NOTIFICATION_COUNTS, {
    equal: sameNotificationCounts,
  });
  /** Unseen social notifications per network; only the server moves them (#2240). */
  readonly notificationCounts = this.storedNotificationCounts.asReadonly();
  readonly unseenNotifications = computed(() => unseenNetworks(this.notificationCounts()));
  readonly unseenNotificationsTotal = computed(() => totalUnseen(this.notificationCounts()));

  readonly tagTree = computed(() =>
    buildTagTree(this.subscriptions(), this.tags.tags(), this.unreadTotals().tags),
  );
  readonly tagNodeById = computed(() => tagNodesById(this.tagTree()));
  /** Social feeds live in their network's section, and an empty saved feed shows nowhere. */
  readonly untagged = computed(() =>
    untaggedSubscriptions(
      this.subscriptions().filter(
        (subscription) => !isSocialFeed(subscription) && !isEmptySavedFeed(subscription),
      ),
    ),
  );
  /** Each sidebar section's own list: what its header counts and its combined view holds. */
  readonly feedGroupCounts = computed<Record<FeedGroupName, FeedGroupCounts>>(() => {
    const unread = this.unreadTotals().groups;
    return {
      tags: {
        unreadCount: unread.tags,
        entryCount: totalEntryCount(taggedSubscriptions(this.subscriptions())),
      },
      feeds: { unreadCount: unread.feeds, entryCount: totalEntryCount(this.untagged()) },
    };
  });
  readonly socialSections = computed(() =>
    buildSocialSections(this.subscriptions(), this.socialAccounts(), {
      unread: this.unreadTotals(),
      notifications: this.notificationCounts(),
    }),
  );
  readonly totalUnread = computed(() => this.unreadTotals().allItems);
  readonly forYouUnread = computed(() => this.unreadTotals().forYou);
  readonly unhealthy = computed(() => unhealthyFeeds(this.subscriptions()));
  readonly unhealthyCount = computed(() => this.unhealthy().length);

  private readonly loads = new StaleGatedLoad();

  /** Optimistic patches this store applied; a quiet reload compares it across its round trip. */
  private localEdits = 0;

  constructor() {
    onIdentityChange(() => this.invalidate());
    // The badge counts another window than the sidebar now links to: ask for that one.
    effect(() => {
      if (this.trendingWindow.window() === this.trendingUnopened().window) return;
      untracked(() => this.reloadCountsQuietly());
    });
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.resolved.set(false);
    this.loads.run(
      this.api.subscriptions(this.trendingWindow.window()),
      (response) => {
        this.applyCounts(response);
        this.loading.set(false);
        this.resolved.set(true);
      },
      (error) => {
        this.error.set(parseProblem(error as HttpErrorResponse));
        this.loading.set(false);
        this.resolved.set(true);
      },
    );
  }

  private invalidate(): void {
    this.loads.forget();
    this.storedSubscriptions.set([]);
    MARK_NAMES.forEach((mark) => this.markCounts[mark].set(0));
    this.trendingUnopened.set(NO_TRENDING_UNOPENED);
    this.yourStream.set(NO_YOUR_STREAM_TALLY);
    this.socialAccounts.set([]);
    this.lemmyHosts.set([]);
    this.storedUnreadTotals.set(NO_UNREAD_TOTALS);
    this.storedNotificationCounts.set(NO_NOTIFICATION_COUNTS);
    this.loading.set(false);
    this.error.set(null);
    this.resolved.set(false);
  }

  /** Reload sidebar counts only after their freshness window has elapsed. */
  loadIfStale(): void {
    if (!this.loads.isStale()) return;
    this.load();
  }

  /** The counts poll's reload (#708): the same request as `load()`, with none
   *  of its UI. `loading` stays down, `resolved` is never taken back (it gates
   *  the onboarding redirect) — a tick can never spin, flicker, or move the user. */
  reloadQuietlyIfStale(): void {
    this.quietReload(
      () => this.api.subscriptions(this.trendingWindow.window()),
      (response) => this.applyCounts(response),
    );
  }

  /** The counts-only tick (#720): same quiet semantics as
   *  `reloadQuietlyIfStale`, but against the ~5 KB counts endpoint instead of
   *  the 137 KB bootstrap. Patches `unreadCount` into the held list; the full
   *  list with feeds and tags is left to `load()` and the regain reload. */
  reloadCountsIfStale(): void {
    this.quietReload(
      () => this.api.subscriptionCounts(this.trendingWindow.window()),
      (response) => this.applyCountsOnly(response),
    );
  }

  /** Refresh the held list after a write the user made — a move, a reorder, an
   *  edit, a retry — WITHOUT the loading skeleton, so the surface stays put and
   *  only the rows that changed move. No staleness gate: unlike a poll tick, the
   *  caller knows the list moved. Takes over a quiet tick on the wire, whose
   *  answer predates the change. */
  reloadQuietly(): void {
    if (!this.resolved()) return;
    this.quietFetch(
      () => this.api.subscriptions(this.trendingWindow.window()),
      (response) => this.applyCounts(response),
      () => this.reloadQuietly(),
    );
  }

  /** `reloadQuietly` for a change that moved only counts, such as a mark-read. */
  reloadCountsQuietly(): void {
    if (!this.resolved()) return;
    this.quietFetch(
      () => this.api.subscriptionCounts(this.trendingWindow.window()),
      (response) => this.applyCountsOnly(response),
      () => this.reloadCountsQuietly(),
    );
  }

  /** The shared body of every silent tick: refresh only when worth a request,
   *  drop an answer the user has overtaken, never touch `loading`/`resolved`.
   *  The `apply` closure is the only difference between full and counts-only. */
  private quietReload<T extends SidebarTotalsDto>(
    fetch: () => Observable<T>,
    apply: (response: T) => void,
  ): void {
    // A tick refreshes counts; it never bootstraps them. Fetching before the
    // first real load would stamp the freshness clock, silence that load, and
    // leave `resolved` false — so the onboarding redirect could never decide.
    if (!this.resolved()) return;
    if (!this.loads.isStale()) return;

    this.quietFetch(fetch, apply, () => undefined);
  }

  /** Fire a reload that leaves `loading`/`resolved` untouched and drops its
   *  answer if the user has overtaken it, so a silent refresh never flickers
   *  the surface or fights an optimistic edit. A deliberate reload asks again
   *  through `whenOvertaken`, since only its answer carries the change; a poll
   *  tick waits for the next one. The gates that decide WHEN to fire belong to
   *  each caller. */
  private quietFetch<T extends SidebarTotalsDto>(
    fetch: () => Observable<T>,
    apply: (response: T) => void,
    whenOvertaken: () => void,
  ): void {
    const editsWhenSent = this.localEdits;
    this.loads.run(fetch(), (response) => {
      // Only the server edits the totals and the Trending and notification counts,
      // so even an overtaken answer carries the latest.
      this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
      this.trendingUnopened.set(response.trending);
      this.yourStream.set(response.yourStream ?? NO_YOUR_STREAM_TALLY);
      this.storedNotificationCounts.set(notificationCountsFrom(response.notifications));
      // The user changed a feed count while this was on the wire. The server
      // counted before that, so adopting those counts now would put the badge back up.
      if (this.localEdits !== editsWhenSent) {
        whenOvertaken();
        return;
      }
      apply(response);
    });
  }

  private applyCounts(response: SubscriptionsResponse): void {
    this.storedSubscriptions.set(response.subscriptions);
    MARK_NAMES.forEach((mark) => this.markCounts[mark].set(response[toCountField(mark)]));
    this.trendingUnopened.set(response.trending);
    this.yourStream.set(response.yourStream ?? NO_YOUR_STREAM_TALLY);
    this.socialAccounts.set(response.socialAccounts);
    this.lemmyHosts.set(response.lemmyHosts);
    this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
    this.storedNotificationCounts.set(notificationCountsFrom(response.notifications));
  }

  /** Patch unread and entry counts into the list already held, replacing the
   *  array only when a number actually moved, so an unchanged tick keeps
   *  array identity and `tagTree`/`untagged` don't recompute (#720). */
  private applyCountsOnly(response: SubscriptionCountsResponse): void {
    const countsById = new Map(response.subscriptions.map((counts) => [counts.id, counts]));
    let moved = false;
    const next = this.storedSubscriptions().map((sub) => {
      const unreadCount = countsById.get(sub.id)?.unreadCount ?? 0;
      const entryCount = countsById.get(sub.id)?.entryCount ?? 0;
      if (unreadCount === sub.unreadCount && entryCount === sub.entryCount) return sub;
      moved = true;
      return { ...sub, unreadCount, entryCount };
    });
    if (moved) this.storedSubscriptions.set(next);

    MARK_NAMES.forEach((mark) => {
      const count = this.markCounts[mark];
      const value = response[toCountField(mark)];
      if (count() !== value) count.set(value);
    });
  }

  /** Keeps a mark's sidebar badge live after a change without a reload; the next load() reconciles with the
   *  server. Clamped so it never goes negative. */
  bumpCount(mark: Mark, by: number): void {
    ++this.localEdits;
    this.markCounts[mark].update((current) => Math.max(0, current + by));
  }

  markArticleRead(entry: EntryDto): void {
    this.shiftArticleUnread(entry, -1);
  }

  markArticleUnread(entry: EntryDto): void {
    this.shiftArticleUnread(entry, 1);
  }

  private shiftArticleUnread(entry: EntryDto, by: number): void {
    const copyIds = new Set(
      [entry, ...(entry.duplicates ?? []).filter((copy) => !copy.isHidden)].map(
        (copy) => copy.subscriptionId,
      ),
    );
    this.patchSubscriptions((subscription) =>
      copyIds.has(subscription.id)
        ? { ...subscription, unreadCount: Math.max(0, subscription.unreadCount + by) }
        : subscription,
    );
  }

  /** Optimistically apply an exclusion-flag toggle (All items / For you / saved searches) so the
   *  UI reflects it immediately; the caller reconciles with the server. */
  patchLocal(id: number, flags: SubscriptionFlags): void {
    this.patchSubscriptions((subscription) =>
      subscription.id === id ? { ...subscription, ...flags } : subscription,
    );
  }

  /** THE way this store edits its own rows. Every optimistic patch goes through
   *  here so none can forget to register itself against an in-flight reload. */
  private patchSubscriptions(patch: (sub: SubscriptionDto) => SubscriptionDto): void {
    ++this.localEdits;
    this.storedSubscriptions.update((subscriptions) => subscriptions.map(patch));
  }
}

function buildMarkCounts(): Record<Mark, WritableSignal<number>> {
  return Object.fromEntries(MARK_NAMES.map((mark) => [mark, signal(0)])) as Record<
    Mark,
    WritableSignal<number>
  >;
}
