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
import { groupByRun, RunGroup } from '../for-you-runs';
import { planMagazine } from '../magazine/magazine-planner';
import { REVEAL_STEP, isAppendedPage } from '../paging';
import { EntryDto } from '../../models';
import { Selection, isSingleStreamView } from '../../query/query';
import { blocksWithout, ListBlock, runGroupsWithout } from './hidden-overlay';

export interface ListContentOptions {
  readonly entries: Signal<EntryDto[]>;
  readonly hasMore: Signal<boolean>;
  readonly selection: Signal<Selection>;
  readonly newestRunId: Signal<number | null>;
  readonly leavingIds: Signal<ReadonlySet<number>>;
}

/** What the list renders: the entries revealed so far, planned into blocks and
 *  run groups, minus the rows hidden by a mark-above-read. Built in a field
 *  initializer, so its effects are created there. */
export class ListContent {
  private readonly zone = inject(NgZone);

  /** How many of `entries()` are rendered. Trails the list while an appended
   *  page is revealed a step per frame (#501); equals the length otherwise. */
  private readonly revealedCount = signal(0);
  private revealFrame = 0;
  private lastEntries: EntryDto[] = [];

  readonly rendered = computed(() => {
    const all = this.options.entries();
    const count = this.revealedCount();
    return count >= all.length ? all : all.slice(0, count);
  });
  private readonly fullyRevealed = computed(() => this.rendered() === this.options.entries());

  private readonly _revealAppended = effect(() => {
    const next = this.options.entries();
    const previous = this.lastEntries;
    this.lastEntries = next;
    untracked(() => this.startReveal(previous, next));
  });

  /** The loaded entries split into one group per recommendation run (#348). One
   *  run-less group for every non-for-you view, so those render exactly as before. */
  readonly runGroups = computed<RunGroup[]>(() => groupByRun(this.rendered()));

  readonly blocks = computed<ListBlock[]>(() => {
    const groups = this.runGroups();
    // Only aggregated views collapse same-source runs into a group widget; a
    // single-stream view (a feed, or the for-you list) must not.
    const grouping = !isSingleStreamView(this.options.selection());
    const complete = !this.options.hasMore() && this.fullyRevealed();

    // Fast path: no dividers (every non-for-you view, and a for-you list showing
    // only the newest run). Plan the whole list at once — identical to before.
    if (!groups.some((group) => this.showRunHeader(group))) {
      return planMagazine({ entries: this.rendered(), grouping, complete });
    }

    const out: ListBlock[] = [];
    groups.forEach((group, index) => {
      if (this.showRunHeader(group)) {
        out.push({ kind: 'run-header', generatedAt: group.generatedAt! });
      }
      // Only the last loaded group may still grow on the next page; every earlier
      // group is provably complete (a different run follows it).
      const groupComplete = index === groups.length - 1 ? complete : true;
      out.push(...planMagazine({ entries: group.entries, grouping, complete: groupComplete }));
    });
    return out;
  });

  /** Ids hidden from the render after a mark-above-read on an unread view — a
   *  post-plan overlay, never fed back into `entries()`, so the planner does
   *  not re-run and every block below the boundary keeps its position (#1080). */
  readonly hiddenAboveIds = signal<ReadonlySet<number>>(new Set());

  /** Keyed by id, not geometry, so a breakpoint or layout swap keeps the overlay;
   *  only a new selection drops it (the reload edge is the scroll restore's). */
  private readonly _resetHiddenAbove = effect(() => {
    this.options.selection();
    this.hiddenAboveIds.set(new Set());
  });

  readonly visibleBlocks = computed(() => blocksWithout(this.blocks(), this.hiddenAboveIds()));
  readonly visibleRunGroups = computed(() =>
    runGroupsWithout(this.runGroups(), this.hiddenAboveIds()),
  );

  /** Rows the user can still see — loaded set minus the collapsed and the
   *  mark-above-hidden ones. The empty state keys on this, not `entries().length`,
   *  so removing the last row shows "nothing here" immediately. */
  readonly visibleEntryCount = computed(() => this.countVisible(this.options.entries()));

  /** `visibleEntryCount` over the rows already in the DOM. */
  readonly renderedVisibleCount = computed(() =>
    this.fullyRevealed() ? this.visibleEntryCount() : this.countVisible(this.rendered()),
  );

  /** Every page is loaded and revealed, so the rendered rows are the whole list. */
  readonly complete = computed(() => !this.options.hasMore() && this.fullyRevealed());

  constructor(private readonly options: ListContentOptions) {
    inject(DestroyRef).onDestroy(() => this.cancelReveal());
  }

  /** Whether a run group opens with a divider. Suppressed only for the run the
   *  header already names ("Last refreshed"), matched by id; every other run
   *  gets one, even at the top. No run id (non-for-you view) means never. */
  showRunHeader(group: RunGroup): boolean {
    return group.runId != null && group.runId !== this.options.newestRunId();
  }

  hide(ids: number[]): void {
    this.hiddenAboveIds.update((current) => new Set([...current, ...ids]));
  }

  clearHidden(): void {
    this.hiddenAboveIds.set(new Set());
  }

  private countVisible(entries: EntryDto[]): number {
    const leaving = this.options.leavingIds();
    const hidden = this.hiddenAboveIds();
    return entries.filter((entry) => !leaving.has(entry.id) && !hidden.has(entry.id)).length;
  }

  private startReveal(previous: EntryDto[], next: EntryDto[]): void {
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
      this.revealedCount.set(this.options.entries().length);
      return;
    }
    this.revealFrame = this.zone.runOutsideAngular(() =>
      requestAnimationFrame(() => {
        this.revealFrame = 0;
        const total = this.options.entries().length;
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
