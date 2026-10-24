import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, WritableSignal, computed, inject, signal } from '@angular/core';
import { Observable, Subscription } from 'rxjs';
import { FeedGroupName } from '../../core/feed-group';
import { Problem, parseProblem } from '../../core/problem';
import { onIdentityChange } from '../../core/auth/session-identity';
import { unhealthyFeeds } from '../feeds/feed-health';
import { ReaderApi } from '../reader-api';
import { countsAreStale } from './sidebar-freshness';
import { TagsStore } from './tags.store';
import { SocialAccountRefDto } from '../social-feed';
import { SubscriptionFlags } from '../subscription-bulk';
import { SubscriptionRows } from './subscription-rows';
import { buildSocialSections, isEmptySavedFeed, isSocialFeed } from './social-sections';
import {
  EntryDto,
  SubscriptionCountsResponse,
  UnreadTotalsDto,
  SubscriptionDto,
  SubscriptionsResponse,
} from '../models';
import {
  FeedGroupCounts,
  buildTagTree,
  tagNodesById,
  sumEntries,
  taggedSubscriptions,
  totalEntryCount,
  untaggedSubscriptions,
} from './subscription-derivations';
import {
  NO_UNREAD_TOTALS,
  UnreadTotals,
  sameUnreadTotals,
  unreadTotalsFrom,
} from './unread-totals';

@Injectable({ providedIn: 'root' })
export class SubscriptionsStore {
  private readonly api = inject(ReaderApi);
  private readonly tags = inject(TagsStore);
  private readonly rows = inject(SubscriptionRows);
  /** Every title resolved for display. */
  readonly subscriptions = this.rows.all;
  /** The rows as the server sent them, which an optimistic edit patches. */
  readonly storedSubscriptions = this.rows.stored;
  readonly favoritesCount = signal(0);
  readonly keptCount = signal(0);
  readonly viewedCount = signal(0);
  readonly socialAccounts = signal<SocialAccountRefDto[]>([]);
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
    buildSocialSections(this.subscriptions(), this.socialAccounts(), this.unreadTotals()),
  );
  readonly totalUnread = computed(() => this.unreadTotals().allItems);
  readonly forYouUnread = computed(() => this.unreadTotals().forYou);
  readonly totalEntries = computed(() => sumEntries(this.subscriptions()));
  readonly unhealthy = computed(() => unhealthyFeeds(this.subscriptions()));
  readonly unhealthyCount = computed(() => this.unhealthy().length);

  private latestLoad = 0;
  private lastLoadedAt = 0;
  private inFlight: Subscription | null = null;

  /** Optimistic patches this store applied; a quiet reload compares it across its round trip. */
  private localEdits = 0;

  constructor() {
    onIdentityChange(() => this.invalidate());
  }

  load(): void {
    const request = this.beginRequest();
    this.loading.set(true);
    this.error.set(null);
    this.resolved.set(false);
    this.inFlight = this.trackWhileOpen(
      this.api.subscriptions().subscribe({
        next: (response) => {
          if (!this.settle(request)) return;
          this.applyCounts(response);
          this.loading.set(false);
          this.resolved.set(true);
        },
        error: (error: HttpErrorResponse) => {
          if (!this.settle(request)) return;
          this.error.set(parseProblem(error));
          this.loading.set(false);
          this.resolved.set(true);
        },
      }),
    );
  }

  /** Claim the store for a new request: abandon whatever it supersedes, stamp
   *  the freshness clock, and take the epoch that tells a late response it has
   *  been overtaken. */
  private beginRequest(): number {
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.lastLoadedAt = Date.now();
    return ++this.latestLoad;
  }

  /** True while `request` is still the store's current one — false once a newer
   *  load, or a logout, has taken it over. */
  private settle(request: number): boolean {
    if (request !== this.latestLoad) return false;
    this.inFlight = null;
    return true;
  }

  /** Park a request in the in-flight slot only while it is still out. A
   *  response that came back synchronously has already left, and holding its
   *  closed subscription would jam the poll's in-flight guard for good. */
  private trackWhileOpen(request: Subscription): Subscription | null {
    return request.closed ? null : request;
  }

  private invalidate(): void {
    ++this.latestLoad;
    this.inFlight?.unsubscribe();
    this.inFlight = null;
    this.lastLoadedAt = 0;
    this.storedSubscriptions.set([]);
    this.favoritesCount.set(0);
    this.keptCount.set(0);
    this.viewedCount.set(0);
    this.socialAccounts.set([]);
    this.storedUnreadTotals.set(NO_UNREAD_TOTALS);
    this.loading.set(false);
    this.error.set(null);
    this.resolved.set(false);
  }

  /** Reload sidebar counts only after their freshness window has elapsed. */
  loadIfStale(): void {
    if (!countsAreStale(this.lastLoadedAt)) return;
    this.load();
  }

  /** The counts poll's reload (#708): the same request as `load()`, with none
   *  of its UI. `loading` stays down, `resolved` is never taken back (it gates
   *  the onboarding redirect) — a tick can never spin, flicker, or move the user. */
  reloadQuietlyIfStale(): void {
    this.quietReload(
      () => this.api.subscriptions(),
      (response) => this.applyCounts(response),
    );
  }

  /** The counts-only tick (#720): same quiet semantics as
   *  `reloadQuietlyIfStale`, but against the ~5 KB counts endpoint instead of
   *  the 137 KB bootstrap. Patches `unreadCount` into the held list; the full
   *  list with feeds and tags is left to `load()` and the regain reload. */
  reloadCountsIfStale(): void {
    this.quietReload(
      () => this.api.subscriptionCounts(),
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
      () => this.api.subscriptions(),
      (response) => this.applyCounts(response),
      () => this.reloadQuietly(),
    );
  }

  /** `reloadQuietly` for a change that moved only counts, such as a mark-read. */
  reloadCountsQuietly(): void {
    if (!this.resolved()) return;
    this.quietFetch(
      () => this.api.subscriptionCounts(),
      (response) => this.applyCountsOnly(response),
      () => this.reloadCountsQuietly(),
    );
  }

  /** The shared body of every silent tick: refresh only when worth a request,
   *  drop an answer the user has overtaken, never touch `loading`/`resolved`.
   *  The `apply` closure is the only difference between full and counts-only. */
  private quietReload<T extends { unreadTotals: UnreadTotalsDto }>(
    fetch: () => Observable<T>,
    apply: (response: T) => void,
  ): void {
    // A tick refreshes counts; it never bootstraps them. Fetching before the
    // first real load would stamp the freshness clock, silence that load, and
    // leave `resolved` false — so the onboarding redirect could never decide.
    if (!this.resolved()) return;
    // A tick already on the wire is about to answer this question; don't stack another.
    if (this.inFlight) return;
    if (!countsAreStale(this.lastLoadedAt)) return;

    this.quietFetch(fetch, apply, () => undefined);
  }

  /** Fire a reload that leaves `loading`/`resolved` untouched and drops its
   *  answer if the user has overtaken it, so a silent refresh never flickers
   *  the surface or fights an optimistic edit. A deliberate reload asks again
   *  through `whenOvertaken`, since only its answer carries the change; a poll
   *  tick waits for the next one. The gates that decide WHEN to fire belong to
   *  each caller. */
  private quietFetch<T extends { unreadTotals: UnreadTotalsDto }>(
    fetch: () => Observable<T>,
    apply: (response: T) => void,
    whenOvertaken: () => void,
  ): void {
    const request = this.beginRequest();
    const editsWhenSent = this.localEdits;
    this.inFlight = this.trackWhileOpen(
      fetch().subscribe({
        next: (response) => {
          if (!this.settle(request)) return;
          // Only the server edits the totals, so even an overtaken answer carries the latest ones.
          this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
          // The user changed a feed count while this was on the wire. The server
          // counted before that, so adopting those counts now would put the badge back up.
          if (this.localEdits !== editsWhenSent) {
            whenOvertaken();
            return;
          }
          apply(response);
        },
        error: () => void this.settle(request),
      }),
    );
  }

  private applyCounts(response: SubscriptionsResponse): void {
    this.storedSubscriptions.set(response.subscriptions);
    this.favoritesCount.set(response.favoritesCount);
    this.keptCount.set(response.keptCount);
    this.viewedCount.set(response.viewedCount);
    this.socialAccounts.set(response.socialAccounts);
    this.storedUnreadTotals.set(unreadTotalsFrom(response.unreadTotals));
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

    this.setIfMoved(this.favoritesCount, response.favoritesCount);
    this.setIfMoved(this.keptCount, response.keptCount);
    this.setIfMoved(this.viewedCount, response.viewedCount);
  }

  private setIfMoved(count: WritableSignal<number>, value: number): void {
    if (count() !== value) count.set(value);
  }

  /** Keep the sidebar favourite/kept badges live after a toggle without a reload;
   *  the next load() reconciles with the server. Clamped so it never goes negative. */
  bumpFavorites(by: number): void {
    this.bumpCount(this.favoritesCount, by);
  }

  bumpKept(by: number): void {
    this.bumpCount(this.keptCount, by);
  }

  /** Keep the Recently-read badge live after an article is opened, without a
   *  reload; the next load() reconciles with the server. The viewed flag is
   *  one-way, so this only ever grows, but it is clamped for symmetry. */
  bumpViewed(by: number): void {
    this.bumpCount(this.viewedCount, by);
  }

  private bumpCount(count: WritableSignal<number>, by: number): void {
    ++this.localEdits;
    count.update((current) => Math.max(0, current + by));
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
