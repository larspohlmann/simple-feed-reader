import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryListComponent } from './entry-list.component';
import { EntryDto } from '../models';
import { ListScrollStore, listScrollKey } from '../list-scroll.store';

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
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [EntryListComponent],
    providers: [provideRouter([])],
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

  it('saves the scroll position under the selection key while scrolling', () => {
    const selection = { kind: 'tag' as const, id: 7, unread: true };
    const f = mount({ selection });
    const store = TestBed.inject(ListScrollStore);
    f.componentInstance.onRowsScroll({ target: { scrollTop: 333 } } as unknown as Event);
    expect(store.restore(listScrollKey(selection))).toBe(333);
  });

  it('re-expands the list header when the selection changes', () => {
    const f = mount();
    f.componentInstance.collapsed.set(true);
    f.detectChanges();
    f.componentRef.setInput('selection', { kind: 'tag', id: 3, unread: true });
    f.detectChanges();
    expect(f.componentInstance.collapsed()).toBe(false);
  });
});
