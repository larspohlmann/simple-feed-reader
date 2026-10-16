import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, WritableSignal, computed, inject, signal } from '@angular/core';
import { Observable, Subscription } from 'rxjs';
import { Problem, parseProblem } from '../../core/problem';
import { onIdentityChange } from '../../core/auth/session-identity';
import { unhealthyFeeds } from '../feeds/feed-health';
import { ReaderApi } from '../reader-api';
import { countsAreStale } from './sidebar-freshness';
import { TagsStore } from './tags.store';
import { SocialAccountRefDto } from '../social-feed';
import { SubscriptionRows } from './subscription-rows';
import { buildSocialSections, isSocialFeed } from './social-sections';
import { NetworkFeedKind, SocialNetworkName } from '../../core/social-network';
import { SubscriptionCountsResponse, SubscriptionDto, SubscriptionsResponse } from '../models';
import {
  buildTagTree,
  sumEntries,
  sumUnread,
  untaggedSubscriptions,
} from './subscription-derivations';

export type ZeroTarget =
  | 'all'
  | { tag: number }
  | { subscription: number }
  | { network: SocialNetworkName; feedKind?: NetworkFeedKind };

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

  readonly tagTree = computed(() => buildTagTree(this.subscriptions(), this.tags.tags()));
  /** Social feeds live in their network's section, so the Feeds list leaves them out. */
  readonly untagged = computed(() =>
    untaggedSubscriptions(
      this.subscriptions().filter((subscription) => !isSocialFeed(subscription)),
    ),
  );
  readonly socialSections = computed(() =>
    buildSocialSections(this.subscriptions(), this.socialAccounts()),
  );
  readonly totalUnread = computed(() => sumUnread(this.subscriptions()));
  readonly totalEntries = computed(() => sumEntries(this.subscriptions()));
  readonly unhealthy = computed(() => unhealthyFeeds(this.subscriptions()));
  readonly unhealthyCount = computed(() => this.unhealthy().length);

  private latestLoad = 0;
  private lastLoadedAt = 0;
  private inFlight: Subscription | null = null;

  /** Counts the optimistic patches this store has applied on its own — a read,
   *  a favourite, an emptied feed. A quiet reload compares it across its own
   *  round trip to know whether the user has overtaken the server's answer. */
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

  /** Refresh the held list after a deliberate action that changed server state
   *  — a manual feed retry — WITHOUT the loading skeleton, so the surface stays
   *  put and only the row that recovered drops out. No staleness gate: unlike a
   *  poll tick, the caller knows the list moved. */
  reloadQuietly(): void {
    if (!this.resolved() || this.inFlight) return;
    this.quietFetch(
      () => this.api.subscriptions(),
      (response) => this.applyCounts(response),
    );
  }

  /** The shared body of every silent tick: refresh only when worth a request,
   *  drop an answer the user has overtaken, never touch `loading`/`resolved`.
   *  The `apply` closure is the only difference between full and counts-only. */
  private quietReload<T>(fetch: () => Observable<T>, apply: (response: T) => void): void {
    // A tick refreshes counts; it never bootstraps them. Fetching before the
    // first real load would stamp the freshness clock, silence that load, and
    // leave `resolved` false — so the onboarding redirect could never decide.
    if (!this.resolved()) return;
    // A request already on the wire is about to answer this tick's question. It
    // is also the one request a tick may NOT cancel: `load()` has taken
    // `resolved` down and only its own response puts it back.
    if (this.inFlight) return;
    if (!countsAreStale(this.lastLoadedAt)) return;

    this.quietFetch(fetch, apply);
  }

  /** Fire a reload that leaves `loading`/`resolved` untouched and drops its
   *  answer if the user has overtaken it, so a silent refresh never flickers
   *  the surface or fights an optimistic edit. The gates that decide WHEN to
   *  fire belong to each caller. */
  private quietFetch<T>(fetch: () => Observable<T>, apply: (response: T) => void): void {
    const request = this.beginRequest();
    const editsWhenSent = this.localEdits;
    this.inFlight = this.trackWhileOpen(
      fetch().subscribe({
        next: (response) => {
          if (!this.settle(request)) return;
          // The user changed a count while this was on the wire (marked read,
          // favourited, emptied a feed). The server counted before that, so
          // adopting the response now would put the badge back up; drop it.
          if (this.localEdits !== editsWhenSent) return;
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
  }

  /** Patch unread and entry counts into the list already held, replacing the
   *  array only when a number actually moved, so an unchanged tick keeps
   *  array identity and `tagTree`/`untagged`/`totalUnread` don't recompute (#720). */
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

  decrementUnread(subscriptionId: number, by = 1): void {
    this.patchSubscriptions((subscription) =>
      subscription.id === subscriptionId
        ? { ...subscription, unreadCount: Math.max(0, subscription.unreadCount - by) }
        : subscription,
    );
  }

  incrementUnread(subscriptionId: number, by = 1): void {
    this.patchSubscriptions((subscription) =>
      subscription.id === subscriptionId
        ? { ...subscription, unreadCount: subscription.unreadCount + by }
        : subscription,
    );
  }

  /** Optimistically apply an exclusion-flag toggle (All items / For you) so the
   *  UI reflects it immediately; the caller reconciles with the server. */
  patchLocal(
    id: number,
    flags: Partial<Pick<SubscriptionDto, 'includeInAllItems' | 'includeInForYou'>>,
  ): void {
    this.patchSubscriptions((subscription) =>
      subscription.id === id ? { ...subscription, ...flags } : subscription,
    );
  }

  zeroUnread(target: ZeroTarget): void {
    this.patchSubscriptions((subscription) => {
      if (target === 'all')
        return subscription.includeInAllItems ? { ...subscription, unreadCount: 0 } : subscription;
      if ('tag' in target)
        return subscription.tags.some((tag) => tag.id === target.tag)
          ? { ...subscription, unreadCount: 0 }
          : subscription;
      if ('network' in target)
        return inNetworkTarget(subscription, target)
          ? { ...subscription, unreadCount: 0 }
          : subscription;
      return subscription.id === target.subscription
        ? { ...subscription, unreadCount: 0 }
        : subscription;
    });
  }

  /** THE way this store edits its own rows. Every optimistic patch goes through
   *  here so none can forget to register itself against an in-flight reload. */
  private patchSubscriptions(patch: (sub: SubscriptionDto) => SubscriptionDto): void {
    ++this.localEdits;
    this.storedSubscriptions.update((subscriptions) => subscriptions.map(patch));
  }
}

function inNetworkTarget(
  subscription: SubscriptionDto,
  target: { network: SocialNetworkName; feedKind?: NetworkFeedKind },
): boolean {
  const social = subscription.social;
  if (social?.network !== target.network) return false;
  return target.feedKind === undefined || social.kind === target.feedKind;
}
