import { TestBed } from '@angular/core/testing';
import { Component, TemplateRef, ViewChild, signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { EntryListComponent, REFRESH_REVEAL } from './entry-list.component';
import { ListScrollMemory } from '../list-scroll-memory';
import { CatalogStore } from '../../discover/catalog.store';
import { prefetchMargin } from '../paging';
import { EntryDto } from '../models';

const memory = { save: jest.fn(), read: jest.fn().mockReturnValue(0) };
// A stub for the two signals `catalogEmpty` reads — keeps the real CatalogStore
// (and its HttpClient chain) out of this component's unit test.
const catalog = { resolved: signal(false), hasEntries: signal(false) };

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: `e${id}`,
  url: null,
  author: null,
  summary: 's',
  contentHtml: null,
  imageUrl: null,
  imageWidth: null,
  imageHeight: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 1,
  source: 'src',
  faviconUrl: null,
  isRead: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  ...over,
});

function mount(over: Record<string, unknown> = {}) {
  memory.save.mockClear();
  memory.read.mockClear().mockReturnValue(0);
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryListComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([]),
      { provide: ListScrollMemory, useValue: memory },
      { provide: CatalogStore, useValue: catalog },
    ],
  });
  const f = TestBed.createComponent(EntryListComponent);
  const inputs = {
    title: 'All items',
    entries: [entry(1), entry(2)],
    loading: false,
    loadingMore: false,
    error: null,
    hasMore: false,
    canMarkAllRead: true,
    selection: { kind: 'all', id: null, unread: true },
    openEntryId: null,
    ...over,
  };
  for (const [k, v] of Object.entries(inputs)) f.componentRef.setInput(k, v);
  f.detectChanges();
  return f;
}

// A standalone host purely to mint a real TemplateRef — NgTemplateOutlet
// accepts one from any module, so it doesn't need to come from the same
// TestBed instance that renders EntryListComponent.
@Component({
  standalone: true,
  template: `<ng-template #tb><p class="top-marker">Top</p></ng-template>`,
})
class TemplateHost {
  @ViewChild('tb', { static: true }) tb!: TemplateRef<unknown>;
}

function topBlockTemplate(): TemplateRef<unknown> {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({ imports: [TemplateHost] });
  const f = TestBed.createComponent(TemplateHost);
  f.detectChanges();
  return f.componentInstance.tb;
}

describe('EntryListComponent', () => {
  // #321: the for-you block is now projected into the top of whichever
  // content branch is live, so it scrolls away with the list instead of
  // sitting in a permanently reserved bar above it.
  describe('topBlock', () => {
    it('renders above the list rows', () => {
      const tb = topBlockTemplate();
      const el = mount({ topBlock: tb, layout: 'list' }).nativeElement as HTMLElement;
      const rows = el.querySelector('.rows')!;
      expect(rows.querySelector('.top-marker')).not.toBeNull();
      // It scrolls with the rows: it lives inside the scroller, not before it.
      expect(rows.firstElementChild!.classList).toContain('top-marker');
    });

    it('renders above the magazine rows', () => {
      const tb = topBlockTemplate();
      const el = mount({ topBlock: tb, layout: 'magazine' }).nativeElement as HTMLElement;
      const rows = el.querySelector('.rows.magazine')!;
      expect(rows.querySelector('.top-marker')).not.toBeNull();
      expect(rows.firstElementChild!.classList).toContain('top-marker');
    });

    it('renders above the empty state, so the run button still shows there', () => {
      const tb = topBlockTemplate();
      const el = mount({ topBlock: tb, entries: [] }).nativeElement as HTMLElement;
      expect(el.querySelector('.top-marker')).not.toBeNull();
      expect(el.querySelector('.empty')).not.toBeNull();
    });

    it('renders nothing extra when no topBlock is provided', () => {
      const el = mount().nativeElement as HTMLElement;
      expect(el.querySelector('.top-marker')).toBeNull();
    });
  });

  describe('recommendation strip', () => {
    const recommended = [
      entry(1, { recommendationReason: 'because you read src', recommendationScore: 910 }),
      entry(2, { recommendationReason: 'similar to your favorites' }),
    ];
    const forYou = { kind: 'for-you', id: null, unread: false };

    it('shows the reason on each for-you entry in the list layout', () => {
      const el = mount({ entries: recommended, selection: forYou, layout: 'list' })
        .nativeElement as HTMLElement;
      const reasons = el.querySelectorAll('app-recommendation-strip .reason');
      expect(reasons.length).toBe(2);
      expect(reasons[0].textContent).toContain('because you read src');
      expect(el.querySelector('app-recommendation-strip .reason .score')!.textContent).toContain(
        '91',
      );
    });

    it('shows the reason on each for-you entry in the magazine layout', () => {
      const el = mount({ entries: recommended, selection: forYou, layout: 'magazine' })
        .nativeElement as HTMLElement;
      const reasons = el.querySelectorAll('app-recommendation-strip .reason');
      expect(reasons.length).toBe(2);
      expect(reasons[0].textContent).toContain('because you read src');
    });

    it('stays inert on a non-for-you view', () => {
      const el = mount({
        entries: [entry(1), entry(2)],
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(el.querySelector('.reason')).toBeNull();
    });
  });

  describe('run-boundary dividers (#348)', () => {
    const forYou = { kind: 'for-you', id: null, unread: false };
    // Run 9 is the newest (its id is what the header names); run 7 is older.
    const NEWEST = '2026-08-09T10:00:00+00:00';
    const OLDER = '2026-08-07T09:05:00+00:00';
    const twoRuns = [
      entry(1, { runId: 9, runGeneratedAt: NEWEST }),
      entry(2, { runId: 9, runGeneratedAt: NEWEST }),
      entry(3, { runId: 7, runGeneratedAt: OLDER }),
    ];

    it('shows a divider at the older run and none above the newest', () => {
      const el = mount({
        entries: twoRuns,
        selection: forYou,
        newestRunId: 9,
        layout: 'list',
      }).nativeElement as HTMLElement;

      // One divider only — for the older run.
      expect(el.querySelectorAll('app-run-header').length).toBe(1);
      // It is not the first child of the scroller (the newest run's rows are).
      const rows = el.querySelector('.rows')!;
      expect(rows.firstElementChild!.tagName.toLowerCase()).not.toBe('app-run-header');
    });

    it('shows a divider on the top block when it is not the newest run', () => {
      // Header names run 9, but run 9 left nothing visible: the first visible
      // entry is run 7, so it gets its own divider.
      const el = mount({
        entries: [entry(3, { runId: 7, runGeneratedAt: OLDER })],
        selection: forYou,
        newestRunId: 9,
        layout: 'list',
      }).nativeElement as HTMLElement;

      expect(el.querySelectorAll('app-run-header').length).toBe(1);
    });

    it('shows no divider on a non-for-you view', () => {
      const el = mount({
        entries: [entry(1), entry(2)],
        selection: { kind: 'all', id: null, unread: true },
        layout: 'list',
      }).nativeElement as HTMLElement;

      expect(el.querySelector('app-run-header')).toBeNull();
    });

    it('shows a divider at the older run in the magazine layout', () => {
      const el = mount({
        entries: twoRuns,
        selection: forYou,
        newestRunId: 9,
        layout: 'magazine',
      }).nativeElement as HTMLElement;

      const rows = el.querySelector('.rows.magazine')!;
      expect(rows.querySelectorAll('app-run-header').length).toBe(1);
      // No magazine block is emitted before the newest run's first block.
      expect(rows.firstElementChild!.tagName.toLowerCase()).not.toBe('app-run-header');
    });
  });

  // #325: the shell projects the For You run/stop button here, right-aligned in
  // the header, without this generic list knowing what the action is.
  describe('headerActions', () => {
    it('renders the projected action inside the list header tools', () => {
      const actions = topBlockTemplate();
      const el = mount({ headerActions: actions }).nativeElement as HTMLElement;
      expect(el.querySelector('.list-header .tools .top-marker')).not.toBeNull();
    });

    it('renders nothing in the header when no headerActions is provided', () => {
      const el = mount().nativeElement as HTMLElement;
      expect(el.querySelector('.list-header .top-marker')).toBeNull();
    });
  });

  it('renders a row per entry and the header title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.list-header')!.textContent).toContain('All items');
    expect(el.querySelectorAll('app-entry-row').length).toBe(2);
  });

  // A tag's heading carries the same glyph and colour its sidebar row does, so
  // the list a reader lands in is recognisably the tag they clicked.
  describe('the tag heading', () => {
    const tag = { id: 4, name: 'Wissenschaft', color: '#c2410c', icon: 'science', position: 0 };
    // The same colour as the DOM reports it back: a style binding is normalised.
    const TAG_RGB = 'rgb(194, 65, 12)';

    it('shows the tag glyph in the tag colour beside the name', () => {
      const el = mount({ title: tag.name, titleTag: tag }).nativeElement as HTMLElement;
      const heading = el.querySelector('.list-header h2')!;
      expect(heading.textContent).toContain('Wissenschaft');

      const icon = heading.querySelector<HTMLElement>('app-tag-glyph app-icon')!;
      expect(icon.textContent!.trim()).toBe('science');
      expect(icon.style.color).toBe(TAG_RGB);
    });

    it('falls back to the colour dot for a tag with no glyph of its own', () => {
      const el = mount({ title: tag.name, titleTag: { ...tag, icon: null } })
        .nativeElement as HTMLElement;
      const heading = el.querySelector('.list-header h2')!;
      expect(heading.querySelector('app-tag-glyph app-icon')).toBeNull();
      expect(heading.querySelector<HTMLElement>('app-tag-glyph .dot')!.style.background).toBe(
        TAG_RGB,
      );
    });

    it('shows no glyph for a heading that names no tag', () => {
      const el = mount().nativeElement as HTMLElement;
      expect(el.querySelector('.list-header h2 app-tag-glyph')).toBeNull();
    });
  });

  // #87: the collapsing list header is a second bar with the same defect as the
  // app header — it used to shrink the list's own box, resizing the scroller
  // mid-gesture. It floats over reserved padding now.
  describe('collapsing list header', () => {
    it('publishes the expanded bar height and keeps it while collapsed', () => {
      const f = mount({ layout: 'list' });
      const host = f.nativeElement as HTMLElement;
      // jsdom has no ResizeObserver, so stand in for the measurement.
      f.componentInstance.headerHeight.set(53);
      f.detectChanges();
      expect(host.style.getPropertyValue('--list-bar-h')).toBe('53px');

      // The reservation must not follow the bar down: the scroller's padding is
      // computed from this value, and shrinking it would move every row.
      f.componentInstance.collapsed.set(true);
      f.detectChanges();
      expect(host.style.getPropertyValue('--list-bar-h')).toBe('53px');
      expect(host.querySelector('.list-header')!.classList).toContain('collapsed');
    });

    it('marks the scroller so it drops the reservation when a banner takes it', () => {
      // Both claiming it would open a gap the height of both bars below the banner.
      const el = mount({ layout: 'list', error: { title: 'Nope', detail: 'Broken' } })
        .nativeElement as HTMLElement;
      expect(el.querySelector('.banner')).not.toBeNull();
      expect(el.querySelector('.rows')!.classList).toContain('after-banner');

      const clean = mount({ layout: 'list' }).nativeElement as HTMLElement;
      expect(clean.querySelector('.rows')!.classList).not.toContain('after-banner');
    });
  });

  it('keeps the current rows rendered and marks them reloading while a reload is on the wire', () => {
    const el = mount({ loading: true, entries: [entry(1), entry(2)], layout: 'list' })
      .nativeElement as HTMLElement;
    expect(el.querySelector('.skeleton')).toBeNull();
    const rows = el.querySelector('.rows')!;
    expect(rows.classList).toContain('reloading');
    expect(rows.querySelectorAll('app-entry-row').length).toBe(2);
  });

  it('makes the retained rows inert while a reload is on the wire', () => {
    // Without this the stale rows stay clickable: a row click during a view
    // switch opens the PREVIOUS view's entry and marks it read (#254).
    const el = mount({ loading: true, entries: [entry(1), entry(2)], layout: 'list' })
      .nativeElement as HTMLElement;
    const rows = el.querySelector('.rows')!;
    expect(rows.hasAttribute('inert')).toBe(true);
    expect(rows.getAttribute('aria-busy')).toBe('true');
  });

  it('drops the inert guard once the rows are current', () => {
    const el = mount({ loading: false, entries: [entry(1)], layout: 'list' })
      .nativeElement as HTMLElement;
    const rows = el.querySelector('.rows')!;
    expect(rows.hasAttribute('inert')).toBe(false);
  });

  it('shows skeletons while loading and an empty state when empty', () => {
    expect(
      (mount({ loading: true, entries: [] }).nativeElement as HTMLElement).querySelector(
        '.skeleton',
      ),
    ).not.toBeNull();
    expect(
      (mount({ loading: false, entries: [] }).nativeElement as HTMLElement).querySelector('.empty'),
    ).not.toBeNull();
  });

  it('shows the search empty state with the term, and no catalog link', () => {
    const el = mount({
      loading: false,
      entries: [],
      selection: { kind: 'search', id: null, unread: false, term: 'angular' },
    }).nativeElement as HTMLElement;
    const empty = el.querySelector('.empty')!;
    expect(empty.textContent).toContain('Nothing matches "angular".');
    expect(empty.textContent).not.toContain('Nothing here yet.');
    expect(empty.querySelector('a')).toBeNull();
  });

  it('keeps the existing empty state and its catalog link for a non-search selection', () => {
    const el = mount({
      loading: false,
      entries: [],
      selection: { kind: 'all', id: null, unread: false },
    }).nativeElement as HTMLElement;
    const empty = el.querySelector('.empty')!;
    expect(empty.textContent).toContain('Nothing here yet.');
    expect(empty.querySelector('a')).not.toBeNull();
  });

  it('emits loadMore from the fallback button and markAllRead', () => {
    const f = mount({ hasMore: true });
    let more = 0,
      mar = 0;
    f.componentInstance.loadMore.subscribe(() => more++);
    f.componentInstance.markAllRead.subscribe(() => mar++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('.load-more') as HTMLButtonElement).click();
    (el.querySelector('.mark-all') as HTMLButtonElement).click();
    expect([more, mar]).toEqual([1, 1]);
  });

  it('keeps the sentinel foot inside the scroll container', () => {
    const el = mount({ hasMore: true }).nativeElement as HTMLElement;
    // The observer root is .rows, so the sentinel must be a descendant of it.
    expect(el.querySelector('.rows .load-more')).not.toBeNull();
  });

  // #91: on a slow backend a fixed 300px lead means scrolling into the spinner.
  // The lead scales with the scroller so it is the same number of screens
  // whatever the window height.
  it('observes the sentinel a viewport-scaled distance ahead', () => {
    const seen: IntersectionObserverInit[] = [];
    const real = globalThis.IntersectionObserver;
    globalThis.IntersectionObserver = class {
      constructor(_cb: IntersectionObserverCallback, init?: IntersectionObserverInit) {
        seen.push(init ?? {});
      }
      readonly observe = jest.fn();
      readonly unobserve = jest.fn();
      readonly disconnect = jest.fn();
      takeRecords(): [] {
        return [];
      }
    } as unknown as typeof IntersectionObserver;

    try {
      // No sentinel yet, so no observer — this lets us fake the scroller's
      // height (jsdom lays nothing out) before the one we care about is made.
      const f = mount({ hasMore: false });
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows')!;
      Object.defineProperty(rows, 'clientHeight', { value: 900, configurable: true });

      f.componentRef.setInput('hasMore', true);
      f.detectChanges();

      expect(seen.at(-1)).toMatchObject({ root: rows, rootMargin: prefetchMargin(900) });
      expect(seen.at(-1)!.rootMargin).not.toBe('300px');
    } finally {
      globalThis.IntersectionObserver = real;
    }
  });

  it('hides mark-all-read when not applicable', () => {
    const el = mount({ canMarkAllRead: false }).nativeElement as HTMLElement;
    expect(el.querySelector('.mark-all')).toBeNull();
  });

  it('emits refresh when the scoped refresh button is clicked', () => {
    const f = mount();
    let hits = 0;
    f.componentInstance.refresh.subscribe(() => hits++);
    (f.nativeElement.querySelector('.refresh') as HTMLButtonElement).click();
    expect(hits).toBe(1);
  });

  it('disables the refresh button while a run is in progress', () => {
    const f = mount({ refreshing: true });
    expect((f.nativeElement.querySelector('.refresh') as HTMLButtonElement).disabled).toBe(true);
  });

  it('hides the scoped refresh button in the cross-feed saved views', () => {
    for (const kind of ['favorites', 'kept'] as const) {
      const el = mount({
        selection: { kind, id: null, unread: false },
        canMarkAllRead: false,
      }).nativeElement as HTMLElement;
      expect(el.querySelector('.refresh')).toBeNull();
    }
  });

  // #105: the gesture had no coverage at all, which is how a threshold that
  // needed ~400px of finger travel shipped. Drive the real listeners on the
  // scroller rather than the handler methods — the wiring is half the feature.
  describe('pull-to-refresh (mobile)', () => {
    // jsdom has no TouchEvent constructor; a plain Event with a touches list is
    // what the handlers actually read.
    function touch(type: string, y: number): Event {
      const e = new Event(type, { bubbles: true, cancelable: true });
      Object.defineProperty(e, 'touches', { value: [{ clientX: 0, clientY: y }] });
      return e;
    }

    /** Drag `distance` px down from the top of the list and let go. */
    function pullBy(f: ReturnType<typeof mount>, distance: number, release = true) {
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      rows.dispatchEvent(touch('touchstart', 100));
      rows.dispatchEvent(touch('touchmove', 100 + distance));
      f.detectChanges();
      if (release) rows.dispatchEvent(touch('touchend', 100 + distance));
      return rows;
    }

    it('refreshes on a pull a thumb can actually make', () => {
      const f = mount();
      let hits = 0;
      f.componentInstance.refresh.subscribe(() => hits++);
      pullBy(f, 140);
      expect(hits).toBe(1);
    });

    it('does not refresh on a short pull', () => {
      const f = mount();
      let hits = 0;
      f.componentInstance.refresh.subscribe(() => hits++);
      pullBy(f, 40);
      expect(hits).toBe(0);
    });

    it('shows the indicator, armed only once the pull is far enough', () => {
      const f = mount();
      pullBy(f, 40, false);
      const chip = (f.nativeElement as HTMLElement).querySelector('.pull-indicator');
      expect(chip).not.toBeNull(); // visible feedback from the first pixels
      expect(chip!.classList).not.toContain('armed');

      pullBy(f, 140, false);
      expect(
        (f.nativeElement as HTMLElement).querySelector('.pull-indicator')!.classList,
      ).toContain('armed');
    });

    it('ignores the gesture in the cross-feed saved views', () => {
      const f = mount({ selection: { kind: 'favorites', id: null, unread: false } });
      let hits = 0;
      f.componentInstance.refresh.subscribe(() => hits++);
      pullBy(f, 140);
      expect(hits).toBe(0);
    });

    it('shows the spinner but no label during the pull (the label is for the running refresh)', () => {
      const f = mount();
      pullBy(f, 140, false);
      const chip = (f.nativeElement as HTMLElement).querySelector('.pull-indicator')!;
      expect(chip).not.toBeNull();
      expect(chip.querySelector('.label')).toBeNull();
    });
  });

  it('labels the refresh button with a refresh icon and text', () => {
    const el = mount().nativeElement as HTMLElement;
    const btn = el.querySelector('.refresh') as HTMLButtonElement;
    expect(btn.querySelector('app-icon[name="refresh"]')).not.toBeNull();
    expect(btn.querySelector('.txt')).not.toBeNull();
  });

  it('shows a last-refreshed hint for a single-feed selection', () => {
    const el = mount({
      selection: { kind: 'subscription', id: 7, unread: true },
      lastRefreshed: '2026-07-25T08:00:00Z',
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.last-refreshed')).not.toBeNull();
  });

  it('shows a last-refreshed hint for the for-you list', () => {
    const el = mount({
      selection: { kind: 'for-you', id: null, unread: false },
      lastRefreshed: '2026-08-08T09:00:00Z',
    }).nativeElement as HTMLElement;
    expect(el.querySelector('.last-refreshed')).not.toBeNull();
  });

  it('shows no last-refreshed hint for all/tag or a never-fetched feed', () => {
    expect(
      (
        mount({
          selection: { kind: 'all', id: null, unread: true },
          lastRefreshed: '2026-07-25T08:00:00Z',
        }).nativeElement as HTMLElement
      ).querySelector('.last-refreshed'),
    ).toBeNull();
    expect(
      (
        mount({ selection: { kind: 'subscription', id: 7, unread: true }, lastRefreshed: null })
          .nativeElement as HTMLElement
      ).querySelector('.last-refreshed'),
    ).toBeNull();
  });

  it('renders planned magazine blocks when layout is magazine', () => {
    // The grouped run must not sit at the very start — the planner leads with
    // featured blocks, never a group. Lead with distinct sources so the
    // collapse-enable gate (>= MIN_VIEW_SOURCES active within 24h) is on, and
    // keep the run >= RUN_MIN so it collapses.
    const lead = [1, 2, 3, 4, 5, 6].map((id) =>
      entry(id, { subscriptionId: id, source: `lead${id}` }),
    );
    const run = [11, 12, 13, 14, 15, 16, 17, 18].map((id) =>
      entry(id, { subscriptionId: 99, source: 'Burst' }),
    );
    const tail = [21, 22, 23, 24, 25, 26, 27].map((id) =>
      entry(id, { subscriptionId: id, source: `tail${id}` }),
    );
    const el = mount({
      layout: 'magazine',
      entries: [...lead, ...run, ...tail],
    }).nativeElement as HTMLElement;
    expect(el.querySelector('app-source-group')).not.toBeNull();
    expect(el.querySelector('.rows.magazine')).not.toBeNull();
  });

  it('renders flat rows when layout is list', () => {
    const el = mount({ layout: 'list' }).nativeElement as HTMLElement;
    expect(el.querySelectorAll('app-entry-row').length).toBe(2);
    expect(el.querySelector('app-source-group')).toBeNull();
  });

  describe('search forces the list layout (#408)', () => {
    it('renders rows, not magazine blocks, for a search selection even when layout is magazine', () => {
      const el = mount({
        layout: 'magazine',
        selection: { kind: 'search', id: null, unread: false, term: 'fox' },
      }).nativeElement as HTMLElement;
      expect(el.querySelectorAll('app-entry-row').length).toBe(2);
      expect(el.querySelector('.rows.magazine')).toBeNull();
    });

    it('still renders magazine blocks for a non-search selection under the magazine layout', () => {
      const el = mount({
        layout: 'magazine',
        selection: { kind: 'all', id: null, unread: true },
      }).nativeElement as HTMLElement;
      expect(el.querySelector('.rows.magazine')).not.toBeNull();
    });
  });

  it('does not collapse the list header by default', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.list-header')!.classList).not.toContain('collapsed');
  });

  it('collapses the list header when the collapsed state is set (scrolled down on mobile)', () => {
    const f = mount();
    f.componentInstance.collapsed.set(true);
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).querySelector('.list-header')!.classList).toContain(
      'collapsed',
    );
  });

  it('re-expands the list header when the selection changes', () => {
    const f = mount();
    f.componentInstance.collapsed.set(true);
    f.detectChanges();
    f.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
    f.detectChanges();
    expect(f.componentInstance.collapsed()).toBe(false);
  });

  it('remembers the scroll offset per selection as the list is scrolled', () => {
    const f = mount();
    f.componentInstance.onRowsScroll({ target: { scrollTop: 480 } } as unknown as Event);
    expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 480);
  });

  it('restores the saved offset once a fresh load completes (return after a resume-reload)', () => {
    // Mount mid-load (skeletons, no scroll container yet) as on a fresh page boot.
    const f = mount({ loading: true, entries: [] });
    memory.read.mockReturnValue(420);
    const apply = jest.spyOn(
      f.componentInstance as unknown as { applyScroll: () => void },
      'applyScroll',
    );

    // The first page lands: loading clears and the rows render.
    f.componentRef.setInput('loading', false);
    f.componentRef.setInput('entries', [entry(1), entry(2)]);
    f.detectChanges();

    expect(memory.read).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true });
    expect(apply).toHaveBeenCalledWith(expect.anything(), 420);
  });

  it('does not restore scroll while the list is still loading', () => {
    memory.read.mockReturnValue(420);
    const f = mount({ loading: true, entries: [] });
    const apply = jest.spyOn(
      f.componentInstance as unknown as { applyScroll: () => void },
      'applyScroll',
    );
    f.detectChanges();
    expect(apply).not.toHaveBeenCalled();
  });

  // #267. The outgoing list stays on screen while the next query runs (#254), so
  // the scroll container survives a view switch and carries the previous view's
  // offset with it. Every case below is about handing the incoming view its own
  // place instead of inheriting that one.
  describe('scroll position across a view switch', () => {
    /** jsdom has no layout, so its scrollTop is permanently 0. Give the scroller
     *  a real one that starts where the previous view left it. */
    function fakeScroller(f: ReturnType<typeof mount>, top: number): HTMLElement {
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      let offset = top;
      Object.defineProperty(rows, 'scrollTop', {
        configurable: true,
        get: () => offset,
        set: (v: number) => {
          offset = v;
        },
      });
      return rows;
    }

    /** Play a load through to completion, which is what marks the rendered rows
     *  as belonging to the current selection. */
    function completeLoad(f: ReturnType<typeof mount>): void {
      f.componentRef.setInput('loading', true);
      f.detectChanges();
      f.componentRef.setInput('loading', false);
      f.detectChanges();
    }

    it('returns to the top when the incoming view has no remembered offset', () => {
      const f = mount();
      const rows = fakeScroller(f, 900);
      memory.read.mockReturnValue(0);

      completeLoad(f);

      expect(rows.scrollTop).toBe(0);
    });

    it('lands on the incoming view’s remembered offset', () => {
      const f = mount();
      const rows = fakeScroller(f, 900);
      memory.read.mockReturnValue(420);

      completeLoad(f);

      expect(rows.scrollTop).toBe(420);
    });

    it('moves the retained list at once, without waiting for its page', () => {
      const f = mount();
      const rows = fakeScroller(f, 900);
      memory.read.mockReturnValue(0);

      f.componentRef.setInput('selection', { kind: 'tag', id: 4, unread: true });
      f.componentRef.setInput('loading', true);
      f.detectChanges();

      expect(rows.scrollTop).toBe(0);
    });

    it('does not write the outgoing list’s scroll into the incoming view’s key', () => {
      const f = mount();
      completeLoad(f);
      f.componentRef.setInput('selection', { kind: 'tag', id: 4, unread: true });
      f.componentRef.setInput('loading', true);
      f.detectChanges();
      memory.save.mockClear();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 480 } } as unknown as Event);

      expect(memory.save).not.toHaveBeenCalled();
    });

    // The guard above must not swallow a scroll during a refresh of the very
    // same view: those rows are the user's own, and dropping the save would
    // yank them back to the pre-refresh offset when the response lands.
    it('keeps remembering the offset while the same view refreshes', () => {
      const f = mount();
      completeLoad(f);
      f.componentRef.setInput('loading', true);
      f.detectChanges();
      memory.save.mockClear();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 480 } } as unknown as Event);

      expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 480);
    });
  });

  describe('back to top', () => {
    /** Stub the scroller: jsdom implements neither scrollTo nor real scrolling. */
    function stubScroller(f: ReturnType<typeof mount>) {
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;
      return { scrollTo };
    }

    it('shows the button only once the list is scrolled well down', () => {
      const f = mount();
      const el = f.nativeElement as HTMLElement;
      expect(el.querySelector('app-to-top-button')).toBeNull();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();
      expect(el.querySelector('app-to-top-button')).not.toBeNull();

      f.componentInstance.onRowsScroll({ target: { scrollTop: 100 } } as unknown as Event);
      f.detectChanges();
      expect(el.querySelector('app-to-top-button')).toBeNull();
    });

    it('scrolls the container to the top, expands the bar and forgets the offset', () => {
      const f = mount();
      const { scrollTo } = stubScroller(f);
      f.componentInstance.collapsed.set(true);
      memory.save.mockClear();

      f.componentInstance.scrollToTop();

      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
      expect(f.componentInstance.collapsed()).toBe(false);
      expect(memory.save).toHaveBeenCalledWith({ kind: 'all', id: null, unread: true }, 0);
    });

    it('clicking the button scrolls to the top', () => {
      const f = mount();
      const { scrollTo } = stubScroller(f);
      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();

      (
        (f.nativeElement as HTMLElement).querySelector(
          'app-to-top-button button',
        ) as HTMLButtonElement
      ).click();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    // #98: the button unmounts once showToTop flips false, which would otherwise
    // drop keyboard focus to <body> and strand a keyboard/screen-reader user.
    it('moves focus to the list title instead of dropping it to the body', () => {
      const f = mount();
      stubScroller(f);
      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();

      (
        (f.nativeElement as HTMLElement).querySelector(
          'app-to-top-button button',
        ) as HTMLButtonElement
      ).click();

      expect(document.activeElement).toBe(
        (f.nativeElement as HTMLElement).querySelector('.list-header .heading h2'),
      );
    });

    it('scrolls the magazine layout’s own container too', () => {
      // The magazine branch renders a different #rows element; scrollToTop has to
      // resolve the live one at call time rather than caching it.
      const f = mount({ layout: 'magazine' });
      const { scrollTo } = stubScroller(f);

      f.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    it('hides the button again when the selection changes', () => {
      const f = mount();
      f.componentInstance.onRowsScroll({ target: { scrollTop: 900 } } as unknown as Event);
      f.detectChanges();
      expect((f.nativeElement as HTMLElement).querySelector('app-to-top-button')).not.toBeNull();

      f.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
      f.detectChanges();
      expect(f.componentInstance.showToTop()).toBe(false);
    });

    it('keeps the bar expanded as the smooth scroll travels back up', () => {
      const f = mount();
      stubScroller(f);
      f.componentInstance.onRowsScroll({ target: { scrollTop: 3000 } } as unknown as Event);
      f.componentInstance.scrollToTop();
      // The animation's own first event, still deep in the list. Against a zeroed
      // baseline this would read as a 2900px scroll *down* and re-collapse the bar.
      f.componentInstance.onRowsScroll({ target: { scrollTop: 2900 } } as unknown as Event);
      expect(f.componentInstance.collapsed()).toBe(false);
    });

    it('does nothing when there is no scroll container yet', () => {
      const f = mount({ entries: [] });
      memory.save.mockClear();
      expect(() => f.componentInstance.scrollToTop()).not.toThrow();
      expect(memory.save).not.toHaveBeenCalled();
    });
  });

  describe('back to top under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: realMatchMedia,
      });
    });

    it('jumps instead of animating', () => {
      // The component reads the flag once, in a field initialiser — so the stub
      // has to be in place before mount(), not before the click.
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: query.includes('prefers-reduced-motion'),
          media: query,
          onchange: null,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }),
      });

      const f = mount();
      const rows = (f.nativeElement as HTMLElement).querySelector('.rows') as HTMLElement;
      const scrollTo = jest.fn();
      rows.scrollTo = scrollTo as unknown as typeof rows.scrollTo;

      f.componentInstance.scrollToTop();
      expect(scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'auto' });
    });
  });

  describe('refresh reveal', () => {
    it('holds the reveal open while a refresh runs and closes it after', () => {
      const f = mount({ refreshing: true });
      expect(f.componentInstance.revealOffset()).toBe(REFRESH_REVEAL);

      f.componentRef.setInput('refreshing', false);
      f.detectChanges();
      expect(f.componentInstance.revealOffset()).toBe(0);
    });

    it('opens the reveal from a button refresh with no pull, and labels it', () => {
      // The list-header button and the sidebar button both just flip refreshing();
      // the reveal reads that, not the gesture, so no pull is involved here.
      const el = mount({ refreshing: true }).nativeElement as HTMLElement;
      expect(el.querySelector('.pull-indicator')).not.toBeNull();
      expect(el.querySelector('.pull-indicator .label')).not.toBeNull();
    });

    it('does not paint the tray over the skeleton while a refresh runs during the initial load', () => {
      const el = mount({ loading: true, entries: [], refreshing: true })
        .nativeElement as HTMLElement;
      expect(el.querySelector('.pull-indicator')).toBeNull();
    });

    it('does not paint the tray over the empty state while a refresh runs', () => {
      const el = mount({ loading: false, entries: [], refreshing: true })
        .nativeElement as HTMLElement;
      expect(el.querySelector('.pull-indicator')).toBeNull();
    });
  });

  describe('refresh reveal under prefers-reduced-motion', () => {
    const realMatchMedia = window.matchMedia;

    beforeEach(() => {
      Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string) => ({
          matches: query.includes('prefers-reduced-motion'),
          media: query,
          onchange: null,
          addEventListener: () => undefined,
          removeEventListener: () => undefined,
          addListener: () => undefined,
          removeListener: () => undefined,
          dispatchEvent: () => false,
        }),
      });
    });

    afterEach(() => {
      Object.defineProperty(window, 'matchMedia', { writable: true, value: realMatchMedia });
    });

    it('does not reveal, even while refreshing', () => {
      const f = mount({ refreshing: true });
      expect(f.componentInstance.revealOffset()).toBe(0);
      expect((f.nativeElement as HTMLElement).querySelector('.pull-indicator')).toBeNull();
    });
  });

  describe('for-you grouping', () => {
    const now = '2026-07-22T11:00:00Z';
    const run = [
      ...Array.from({ length: 8 }, (_, i) =>
        entry(i + 1, { subscriptionId: 1, source: 'a', publishedAt: now }),
      ),
      entry(9, { subscriptionId: 2, source: 'b', publishedAt: now }),
      entry(10, { subscriptionId: 2, source: 'b', publishedAt: now }),
      entry(11, { subscriptionId: 3, source: 'c', publishedAt: now }),
      entry(12, { subscriptionId: 3, source: 'c', publishedAt: now }),
    ];

    it('collapses a same-source run in an aggregated view', () => {
      const f = mount({
        entries: run,
        selection: { kind: 'all', id: null, unread: false },
        layout: 'magazine',
      });
      expect(f.componentInstance.blocks().some((b) => b.kind === 'group')).toBe(true);
    });

    it('never collapses the for-you list', () => {
      const f = mount({
        entries: run,
        selection: { kind: 'for-you', id: null, unread: false },
        layout: 'magazine',
      });
      expect(f.componentInstance.blocks().some((b) => b.kind === 'group')).toBe(false);
    });
  });
});
