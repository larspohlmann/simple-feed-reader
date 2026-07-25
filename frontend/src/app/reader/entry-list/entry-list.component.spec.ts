import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideRouter } from '@angular/router';
import { EntryListComponent } from './entry-list.component';
import { ListScrollMemory } from '../list-scroll-memory';
import { EntryDto } from '../models';

const memory = { save: jest.fn(), read: jest.fn().mockReturnValue(0) };

const entry = (id: number, over: Partial<EntryDto> = {}): EntryDto => ({
  id,
  title: `e${id}`,
  url: null,
  author: null,
  summary: 's',
  contentHtml: null,
  publishedAt: '2026-07-22T11:00:00Z',
  createdAt: 'x',
  subscriptionId: 1,
  source: 'src',
  isRead: false,
  isFavorite: false,
  isKept: false,
  ...over,
});

function mount(over: Record<string, unknown> = {}) {
  memory.save.mockClear();
  memory.read.mockClear().mockReturnValue(0);
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryListComponent, provideTranslocoTesting()],
    providers: [provideRouter([]), { provide: ListScrollMemory, useValue: memory }],
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

describe('EntryListComponent', () => {
  it('renders a row per entry and the header title', () => {
    const el = mount().nativeElement as HTMLElement;
    expect(el.querySelector('.list-header')!.textContent).toContain('All items');
    expect(el.querySelectorAll('app-entry-row').length).toBe(2);
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
    const grouped = [1, 2, 3].map((id) => entry(id));
    const diverse = entry(4, { subscriptionId: 4, source: 'diverse' });
    const el = mount({
      layout: 'magazine',
      entries: [...grouped, diverse],
    }).nativeElement as HTMLElement;
    expect(el.querySelector('app-source-group')).not.toBeNull();
    expect(el.querySelector('.rows.magazine')).not.toBeNull();
  });

  it('renders flat rows when layout is list', () => {
    const el = mount({ layout: 'list' }).nativeElement as HTMLElement;
    expect(el.querySelectorAll('app-entry-row').length).toBe(2);
    expect(el.querySelector('app-source-group')).toBeNull();
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
});
