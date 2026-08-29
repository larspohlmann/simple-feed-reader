// src/app/settings/organise/organise.store.ts
import { Injectable, Signal, computed, effect, inject, signal, untracked } from '@angular/core';
import { SubscriptionsStore, untaggedSubs } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { SubscriptionDto, TagDto } from '../../reader/models';

/** A group is one tag, or the untagged bucket that always sits last. */
export type GroupKey = number | 'untagged';
export type OrganiseView = 'tree' | 'list';
export type OrganiseSort = 'title' | 'added';
/** What a group's checkbox shows: nothing, some of its feeds, or all of them. */
export type GroupState = 'none' | 'some' | 'all';

export interface OrganiseGroup {
  readonly key: GroupKey;
  /** null for the untagged group. */
  readonly tag: TagDto | null;
  /** The feeds this group shows, already filtered and in their stored order. */
  readonly subscriptions: SubscriptionDto[];
  /** How many feeds the group holds before the filter — the header's count. */
  readonly totalCount: number;
}

/** Its own key, deliberately not the sidebar's `sfr.tags.collapsed`: collapsing
 *  a group here must not collapse the sidebar the user navigates with. */
const EXPANDED_KEY = 'sfr.organise.expanded';

function readExpanded(): ReadonlySet<GroupKey> {
  try {
    const raw = localStorage.getItem(EXPANDED_KEY);
    if (raw === null) return new Set();
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? new Set(parsed as GroupKey[]) : new Set();
  } catch {
    // A corrupt or unreadable value is not worth a broken page: start closed.
    return new Set();
  }
}

/**
 * The feeds carrying one tag, in that tag's own order.
 *
 * `buildTagTree` in subscriptions.store.ts sorts the same way and also
 * computes unread counts this page has no use for, so it sorts here rather
 * than bending that function to two callers. Both build their tag NODES from
 * the full tag list (every tag shows, empty ones included) — `buildTagTree`
 * drops a tag only in its no-`orderedTags` fallback, which this page's
 * `tags()` signal never hits.
 */
function feedsInTag(subscriptions: SubscriptionDto[], tagId: number): SubscriptionDto[] {
  const position = (s: SubscriptionDto): number =>
    s.tags.find((t) => t.id === tagId)?.position ?? 0;

  return subscriptions
    .filter((s) => s.tags.some((t) => t.id === tagId))
    .sort((a, b) => position(a) - position(b));
}

/**
 * The page's own state: what is selected, what is open, what is filtered.
 *
 * It performs no writes and injects no write-capable service. Every change to
 * the data goes through the page's own action layer, which is what keeps this
 * page, the sidebar's Organise mode and settings/tags from drifting apart.
 *
 * Provided by the page component, not in root: leaving the page must drop the
 * selection rather than leave it waiting.
 */
@Injectable()
export class OrganiseStore {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tagsStore = inject(TagsStore);

  readonly selectedIds = signal<ReadonlySet<number>>(new Set());
  readonly expandedKeys = signal<ReadonlySet<GroupKey>>(readExpanded());

  /** A selected feed that another tab, or the row menu's own "Unsubscribe",
   *  removes from the loaded subscriptions must drop out of the selection
   *  too — the backend 422s a bulk request whole when ANY id in it no longer
   *  exists, so a stale id would silently fail every later bulk action for
   *  the rest of the selection as well. */
  private readonly pruneSelectionOfRemovedFeeds = effect(() => {
    const known = new Set(this.subs.subscriptions().map((s) => s.id));
    untracked(() => {
      this.selectedIds.update((current) => {
        const next = new Set([...current].filter((id) => known.has(id)));

        return next.size === current.size ? current : next;
      });
    });
  });
  readonly view = signal<OrganiseView>('tree');
  readonly titleFilter = signal('');
  readonly tagFilter = signal<ReadonlySet<GroupKey>>(new Set());
  readonly sort = signal<OrganiseSort>('title');
  /** True while a bulk write is in flight; the bulk bar disables itself. */
  readonly busy = signal(false);

  readonly tags: Signal<TagDto[]> = this.tagsStore.tags;

  readonly filterActive = computed(
    () => this.titleFilter().trim() !== '' || this.tagFilter().size > 0,
  );

  readonly filteredSubscriptions = computed<SubscriptionDto[]>(() => {
    const term = this.titleFilter().trim().toLocaleLowerCase();
    const tagKeys = this.tagFilter();

    return this.subs.subscriptions().filter((s) => {
      if (term !== '' && !s.title.toLocaleLowerCase().includes(term)) return false;
      if (tagKeys.size === 0) return true;
      if (tagKeys.has('untagged') && s.tags.length === 0) return true;
      return s.tags.some((t) => tagKeys.has(t.id));
    });
  });

  /** Each group's total feed count before any filter. Kept out of `groups()`
   *  itself: it depends only on `subs.subscriptions()` and `tags()`, never on
   *  `titleFilter`/`tagFilter`, so sharing `groups()`'s computed body would
   *  re-derive every tag's total on every keystroke in the filter box for
   *  nothing. */
  private readonly totalCounts = computed<ReadonlyMap<GroupKey, number>>(() => {
    const all = this.subs.subscriptions();
    const counts = new Map<GroupKey, number>();
    for (const tag of this.tags()) {
      counts.set(tag.id, feedsInTag(all, tag.id).length);
    }
    counts.set('untagged', untaggedSubs(all).length);

    return counts;
  });

  readonly groups = computed<OrganiseGroup[]>(() => {
    const visible = this.filteredSubscriptions();
    const totalCounts = this.totalCounts();

    const tagGroups: OrganiseGroup[] = this.tags().map((tag) => ({
      key: tag.id,
      tag,
      subscriptions: feedsInTag(visible, tag.id),
      totalCount: totalCounts.get(tag.id) ?? 0,
    }));

    const groups: OrganiseGroup[] = [
      ...tagGroups,
      {
        key: 'untagged',
        tag: null,
        subscriptions: untaggedSubs(visible),
        totalCount: totalCounts.get('untagged') ?? 0,
      },
    ];

    // With no filter every tag shows, empty ones included — that IS the
    // arrangement. With a filter, a group that matches nothing is noise.
    return this.filterActive() ? groups.filter((g) => g.subscriptions.length > 0) : groups;
  });

  /** The flat view's rows: every filtered feed once, in the chosen sort. */
  readonly listRows = computed<SubscriptionDto[]>(() => {
    const rows = [...this.filteredSubscriptions()];
    if (this.sort() === 'added') {
      return rows.sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    }

    return rows.sort((a, b) => a.title.localeCompare(b.title));
  });

  /** Every feed the filter currently shows, counted once. */
  readonly visibleIds = computed<ReadonlySet<number>>(
    () => new Set(this.filteredSubscriptions().map((s) => s.id)),
  );

  readonly selectedCount = computed(() => this.selectedIds().size);

  readonly hiddenSelectedCount = computed(() => {
    const visible = this.visibleIds();
    return [...this.selectedIds()].filter((id) => !visible.has(id)).length;
  });

  readonly selectedSubscriptions = computed<SubscriptionDto[]>(() => {
    const selected = this.selectedIds();
    return this.subs.subscriptions().filter((s) => selected.has(s.id));
  });

  readonly allVisibleSelected = computed(() => {
    const visible = this.visibleIds();
    if (visible.size === 0) return false;
    const selected = this.selectedIds();

    return [...visible].every((id) => selected.has(id));
  });

  toggleFeed(id: number): void {
    this.selectedIds.update((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);

      return next;
    });
  }

  setGroupSelected(group: OrganiseGroup, selected: boolean): void {
    this.selectedIds.update((current) => {
      const next = new Set(current);
      for (const s of group.subscriptions) {
        if (selected) next.add(s.id);
        else next.delete(s.id);
      }

      return next;
    });
  }

  groupState(group: OrganiseGroup): GroupState {
    if (group.subscriptions.length === 0) return 'none';
    const selected = this.selectedIds();
    const hits = group.subscriptions.filter((s) => selected.has(s.id)).length;
    if (hits === 0) return 'none';

    return hits === group.subscriptions.length ? 'all' : 'some';
  }

  toggleSelectAllVisible(): void {
    const visible = this.visibleIds();
    const selectAll = !this.allVisibleSelected();
    this.selectedIds.update((current) => {
      const next = new Set(current);
      for (const id of visible) {
        if (selectAll) next.add(id);
        else next.delete(id);
      }

      return next;
    });
  }

  clearSelection(): void {
    this.selectedIds.set(new Set());
  }

  /** A filter forces its matching groups open — a match inside a closed group
   *  is a match the user cannot see. */
  isExpanded(key: GroupKey): boolean {
    return this.filterActive() || this.expandedKeys().has(key);
  }

  toggleGroup(key: GroupKey): void {
    this.expandedKeys.update((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      persistExpanded(next);

      return next;
    });
  }

  /** Every group key that exists, independent of the title/tag filter —
   *  `groups()` drops a group with no filter match, and expandAll/collapseAll
   *  must persist a choice about ALL of the user's groups, not just the ones
   *  a filter happens to be showing right now. */
  private readonly allGroupKeys = computed<GroupKey[]>(() => [
    ...this.tags().map((tag) => tag.id),
    'untagged',
  ]);

  expandAll(): void {
    const next = new Set<GroupKey>(this.allGroupKeys());
    persistExpanded(next);
    this.expandedKeys.set(next);
  }

  collapseAll(): void {
    const next = new Set<GroupKey>();
    persistExpanded(next);
    this.expandedKeys.set(next);
  }
}

function persistExpanded(keys: ReadonlySet<GroupKey>): void {
  try {
    localStorage.setItem(EXPANDED_KEY, JSON.stringify([...keys]));
  } catch {
    // A full or blocked storage must not break the page; the state simply
    // does not survive a reload.
  }
}
