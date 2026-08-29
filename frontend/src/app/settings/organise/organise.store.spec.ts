import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { SubscriptionDto, TagDto } from '../../reader/models';

const tag = (id: number, name: string, position: number): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position,
});

const sub = (id: number, title: string, tagIds: number[] = [], position = 0): SubscriptionDto =>
  ({
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://feed-${id}.example/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: `Tag ${tagId}`,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

describe('OrganiseStore', () => {
  const TAGS = [tag(1, 'Nachrichten', 0), tag(2, 'Tech', 1)];
  const SUBS = [
    sub(10, 'taz', [1], 0),
    sub(11, 'heise', [2], 0),
    sub(12, 'netzpolitik', [1, 2], 1),
    sub(13, 'Untagged feed', [], 0),
  ];

  function make(subs: SubscriptionDto[] = SUBS, tags: TagDto[] = TAGS): OrganiseStore {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        OrganiseStore,
        { provide: SubscriptionsStore, useValue: { subscriptions: signal(subs) } },
        { provide: TagsStore, useValue: { tags: signal(tags) } },
      ],
    });

    return TestBed.inject(OrganiseStore);
  }

  it('groups feeds under every tag and puts untagged last', () => {
    const store = make();

    const keys = store.groups().map((g) => g.key);
    expect(keys).toEqual([1, 2, 'untagged']);
    expect(store.groups()[0].subscriptions.map((s) => s.id)).toEqual([10, 12]);
    expect(store.groups()[2].subscriptions.map((s) => s.id)).toEqual([13]);
  });

  it('shows a feed with two tags in both groups', () => {
    const store = make();

    expect(store.groups()[0].subscriptions.map((s) => s.id)).toContain(12);
    expect(store.groups()[1].subscriptions.map((s) => s.id)).toContain(12);
  });

  it('selects a feed everywhere it appears, and counts it once', () => {
    const store = make();

    store.toggleFeed(12);

    expect(store.selectedIds().has(12)).toBe(true);
    expect(store.selectedCount()).toBe(1);
    // netzpolitik (12) carries both tags. Group 0 (Nachrichten: taz, netzpolitik)
    // has one of two selected -> 'some'. Group 1 (Tech: heise, netzpolitik) also
    // has one of two selected -> 'some' too: heise is untouched, so Tech cannot
    // be 'all'. (The brief's draft asserted 'all' here; that is inconsistent
    // with Tech having two members, per the "shows a feed with two tags" test
    // above and the "narrows by title filter" test below, both of which require
    // heise to carry tag 2.)
    expect(store.groupState(store.groups()[0])).toBe('some');
    expect(store.groupState(store.groups()[1])).toBe('some');
  });

  it('reports a group as all when every one of its feeds is selected', () => {
    const store = make();

    store.setGroupSelected(store.groups()[0], true);

    expect(store.groupState(store.groups()[0])).toBe('all');
    expect(store.selectedCount()).toBe(2);
  });

  it('deselects every feed of a group with setGroupSelected(group, false)', () => {
    const store = make();
    store.setGroupSelected(store.groups()[0], true);
    expect(store.selectedCount()).toBe(2);

    store.setGroupSelected(store.groups()[0], false);

    expect(store.selectedCount()).toBe(0);
    expect(store.groupState(store.groups()[0])).toBe('none');
  });

  it('clears the whole selection', () => {
    const store = make();
    store.toggleFeed(10);
    store.toggleFeed(11);

    store.clearSelection();

    expect(store.selectedCount()).toBe(0);
  });

  it('starts every group collapsed, opens on toggle, closes again on a second toggle', () => {
    const store = make();

    expect(store.isExpanded(1)).toBe(false);

    store.toggleGroup(1);

    expect(store.isExpanded(1)).toBe(true);

    store.toggleGroup(1);

    expect(store.isExpanded(1)).toBe(false);
  });

  it('expandAll opens every current group; collapseAll closes them all', () => {
    const store = make();

    store.expandAll();

    expect(store.isExpanded(1)).toBe(true);
    expect(store.isExpanded(2)).toBe(true);
    expect(store.isExpanded('untagged')).toBe(true);

    store.collapseAll();

    expect(store.isExpanded(1)).toBe(false);
    expect(store.isExpanded(2)).toBe(false);
    expect(store.isExpanded('untagged')).toBe(false);
  });

  it('reports a group as none when nothing in it is selected', () => {
    const store = make();
    store.toggleFeed(11);

    // heise (11) is in the Tech group only; the Nachrichten group (taz,
    // netzpolitik) has neither selected.
    expect(store.groupState(store.groups()[0])).toBe('none');
  });

  it('narrows the groups by the title filter', () => {
    const store = make();

    store.titleFilter.set('heise');

    expect(store.groups().map((g) => g.key)).toEqual([2]);
    expect(store.groups()[0].subscriptions.map((s) => s.id)).toEqual([11]);
  });

  it('expands every matching group while a filter is active', () => {
    const store = make();
    store.collapseAll();

    store.titleFilter.set('heise');

    expect(store.isExpanded(2)).toBe(true);
  });

  it('finds untagged feeds through the tag filter', () => {
    const store = make();

    store.tagFilter.set(new Set(['untagged']));

    expect(store.groups().map((g) => g.key)).toEqual(['untagged']);
  });

  it('select all takes only what the filter shows', () => {
    const store = make();
    store.titleFilter.set('heise');

    store.toggleSelectAllVisible();

    expect([...store.selectedIds()]).toEqual([11]);
  });

  it('reports all-visible-selected only once every visible feed is picked, and toggles both ways', () => {
    const store = make();
    store.titleFilter.set('heise');

    expect(store.allVisibleSelected()).toBe(false);

    store.toggleSelectAllVisible();

    expect(store.allVisibleSelected()).toBe(true);

    store.toggleSelectAllVisible();

    expect(store.allVisibleSelected()).toBe(false);
    expect(store.selectedCount()).toBe(0);
  });

  it('counts the selected feeds the filter hides', () => {
    const store = make();
    store.toggleFeed(10);
    store.toggleFeed(11);
    store.toggleFeed(12);

    store.titleFilter.set('heise');

    // Only heise (11) stays visible; taz (10) and netzpolitik (12) are hidden.
    // Picking 3 selections against 1 visible match keeps hidden (2) and
    // visible-and-selected (1) from coinciding, so a hiddenSelectedCount that
    // accidentally counted the wrong side of the filter would be caught here.
    expect(store.selectedCount()).toBe(3);
    expect(store.hiddenSelectedCount()).toBe(2);
  });

  it('resolves the selection to the actual subscription objects', () => {
    const store = make();
    store.toggleFeed(10);
    store.toggleFeed(12);

    expect(
      store
        .selectedSubscriptions()
        .map((s) => s.title)
        .sort(),
    ).toEqual(['netzpolitik', 'taz']);
  });

  it('keeps the selection when the view switches', () => {
    const store = make();
    store.toggleFeed(10);

    store.view.set('list');

    expect(store.selectedCount()).toBe(1);
  });

  it('sorts the list view by title', () => {
    const store = make();

    store.view.set('list');

    expect(store.listRows().map((s) => s.title)).toEqual([
      'heise',
      'netzpolitik',
      'taz',
      'Untagged feed',
    ]);
  });

  it('persists the collapsed groups under its own key, not the sidebar key', () => {
    const store = make();

    store.toggleGroup(1);

    expect(localStorage.getItem('sfr.tags.collapsed')).toBeNull();
    expect(localStorage.getItem('sfr.organise.expanded')).not.toBeNull();
  });
});
