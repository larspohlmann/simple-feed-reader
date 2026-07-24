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
