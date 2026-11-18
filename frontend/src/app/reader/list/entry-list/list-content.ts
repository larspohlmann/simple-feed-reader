import {
  DestroyRef,
  NgZone,
  Signal,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { planMagazine } from '../magazine/magazine-planner';
import { REVEAL_STEP, isAppendedPage } from '../paging';
import { ListItem, leadOf } from '../../state/list-item';
import { Selection, isSingleStreamView, listOrderOf } from '../../query/query';
import { sameMembers } from '../../state/same-members';
import { isSavedFeed, isSocialFeed } from '../../state/social-sections';
import { SubscriptionRows } from '../../state/subscription-rows';
import { blocksWithout, groupMembersWithout, ListBlock } from './hidden-overlay';
import { ListSegment, segmentList, segmentsWithout } from './list-segments';

export interface ListContentOptions {
  readonly items: Signal<ListItem[]>;
  readonly hasMore: Signal<boolean>;
  readonly selection: Signal<Selection>;
  readonly newestRunId: Signal<number | null>;
  readonly leavingIds: Signal<ReadonlySet<number>>;
  readonly newSinceVisitIds: Signal<ReadonlySet<number>>;
}

/** What the list renders: the items revealed so far, planned into blocks and
 *  segments, minus the rows hidden by a mark-above-read. Built in a field
 *  initializer, so its effects are created there. */
export class ListContent {
  private readonly zone = inject(NgZone);
  private readonly subscriptions = inject(SubscriptionRows);

  /** A network subscription or a saved feed is never filed under one source in a group widget. Equal by
   *  members, so a counts-only refresh does not re-plan. */
  private readonly uncollapsibleSources = computed<ReadonlySet<number>>(
    () =>
      new Set(
        this.subscriptions
          .stored()
          .filter((subscription) => isSocialFeed(subscription) || isSavedFeed(subscription))
          .map(({ id }) => id),
      ),
    { equal: sameMembers },
  );

  /** How many of `items()` are rendered. Trails the list while an appended
   *  page is revealed a step per frame (#501); equals the length otherwise. */
  private readonly revealedCount = signal(0);
  private revealFrame = 0;
  private lastItems: ListItem[] = [];

  readonly rendered = computed(() => {
    const all = this.options.items();
    const count = this.revealedCount();
    return count >= all.length ? all : all.slice(0, count);
  });
  private readonly fullyRevealed = computed(() => this.rendered() === this.options.items());

  private readonly _revealAppended = effect(() => {
    const next = this.options.items();
    const previous = this.lastItems;
    this.lastItems = next;
    untracked(() => this.startReveal(previous, next));
  });

  /** The rendered items cut at every run header and the visit's divider, for both layouts. */
  readonly segments = computed<ListSegment[]>(() =>
    segmentList(this.rendered(), {
      showsRunHeader: (group) => group.runId != null && group.runId !== this.options.newestRunId(),
      newSinceVisitIds: this.options.newSinceVisitIds(),
      newestFirst: listOrderOf(this.options.selection()) === 'newest',
    }),
  );

  /** Each segment planned on its own behind its divider; a plain list is one plan, as before. */
  readonly blocks = computed<ListBlock[]>(() => {
    // Only multi-feed views collapse same-source runs into a group widget; a
    // single-stream view (a feed, or the for-you list) must not.
    const collapse = {
      grouping: !isSingleStreamView(this.options.selection()),
      uncollapsibleSources: this.uncollapsibleSources(),
    };
    const complete = !this.options.hasMore() && this.fullyRevealed();
    const segments = this.segments();
    return segments.flatMap(({ divider, items }, index) => [
      ...(divider === null ? [] : [divider]),
      ...planMagazine({
        ...collapse,
        items,
        complete: index === segments.length - 1 ? complete : true,
      }),
    ]);
  });

  /** Ids hidden from the render after a mark-above-read on an unread view — a
   *  post-plan overlay, never fed back into `items()`, so the planner does
   *  not re-run and every block below the boundary keeps its position (#1080). */
  readonly hiddenAboveIds = signal<ReadonlySet<number>>(new Set());

  /** Keyed by id, not geometry, so a breakpoint or layout swap keeps the overlay;
   *  only a new selection drops it (the reload edge is the scroll restore's). */
  private readonly _resetHiddenAbove = effect(() => {
    this.options.selection();
    this.hiddenAboveIds.set(new Set());
  });

  readonly visibleBlocks = computed(() =>
    groupMembersWithout(
      blocksWithout(this.blocks(), this.hiddenAboveIds()),
      this.options.leavingIds(),
    ),
  );
  readonly visibleSegments = computed(() =>
    segmentsWithout(this.segments(), this.hiddenAboveIds()),
  );

  /** Rows the user can still see — loaded set minus the collapsed and the
   *  mark-above-hidden ones. The empty state keys on this, not `items().length`,
   *  so removing the last row shows "nothing here" immediately. */
  readonly visibleEntryCount = computed(() => this.countVisible(this.options.items()));

  /** `visibleEntryCount` over the rows already in the DOM. */
  readonly renderedVisibleCount = computed(() =>
    this.fullyRevealed() ? this.visibleEntryCount() : this.countVisible(this.rendered()),
  );

  /** Every page is loaded and revealed, so the rendered rows are the whole list. */
  readonly complete = computed(() => !this.options.hasMore() && this.fullyRevealed());

  constructor(private readonly options: ListContentOptions) {
    inject(DestroyRef).onDestroy(() => this.cancelReveal());
  }

  hide(ids: number[]): void {
    this.hiddenAboveIds.update((current) => new Set([...current, ...ids]));
  }

  clearHidden(): void {
    this.hiddenAboveIds.set(new Set());
  }

  private countVisible(items: ListItem[]): number {
    const leaving = this.options.leavingIds();
    const hidden = this.hiddenAboveIds();
    return items.map((item) => leadOf(item).id).filter((id) => !leaving.has(id) && !hidden.has(id))
      .length;
  }

  private startReveal(previous: ListItem[], next: ListItem[]): void {
    this.cancelReveal();
    if (!isAppendedPage(previous, next)) {
      this.revealedCount.set(next.length);
      return;
    }
    this.revealedCount.update((count) => Math.min(count, previous.length));
    this.scheduleRevealStep();
  }

  private scheduleRevealStep(): void {
    if (typeof requestAnimationFrame === 'undefined') {
      this.revealedCount.set(this.options.items().length);
      return;
    }
    this.revealFrame = this.zone.runOutsideAngular(() =>
      requestAnimationFrame(() => {
        this.revealFrame = 0;
        const total = this.options.items().length;
        this.revealedCount.update((count) => Math.min(count + REVEAL_STEP, total));
        if (this.revealedCount() < total) this.scheduleRevealStep();
      }),
    );
  }

  private cancelReveal(): void {
    if (this.revealFrame && typeof cancelAnimationFrame !== 'undefined') {
      cancelAnimationFrame(this.revealFrame);
    }
    this.revealFrame = 0;
  }
}
