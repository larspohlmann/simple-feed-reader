import { TestBed } from '@angular/core/testing';
import { WritableSignal, inject, signal } from '@angular/core';
import { OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { SubscriptionRows } from '../../reader/state/subscription-rows';
import { TagsStore } from '../../reader/state/tags.store';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { makeSubscription } from '../../reader/testing/subscription.factory';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

const tag = (id: number, name: string, position: number): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position,
  parentId: null,
});

const sub = (
  id: number,
  title: string,
  at: { tagIds?: number[]; position?: number } = {},
): SubscriptionDto =>
  makeSubscription({
    id,
    feedId: id,
    title,
    feedUrl: `https://feed-${id}.example/rss`,
    position: at.position ?? 0,
    tags: (at.tagIds ?? []).map((tagId, index) => ({
      id: tagId,
      name: `Tag ${tagId}`,
      color: null,
      icon: null,
      position: index,
      parentId: null,
    })),
  });

describe('OrganiseStore', () => {
  const TAGS = [tag(1, 'Nachrichten', 0), tag(2, 'Tech', 1)];
  const SUBS = [
    sub(10, 'taz', { tagIds: [1] }),
    sub(11, 'heise', { tagIds: [2] }),
    sub(12, 'netzpolitik', { tagIds: [1, 2], position: 1 }),
    sub(13, 'Untagged feed'),
  ];

  function make(subscriptions: SubscriptionDto[] = SUBS, tags: TagDto[] = TAGS): OrganiseStore {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        OrganiseStore,
        {
          provide: SubscriptionsStore,
          useFactory: () => {
            const rows = inject(SubscriptionRows);
            rows.stored.set(subscriptions);
            return { subscriptions: rows.all };
          },
        },
        { provide: TagsStore, useValue: { tags: signal(tags) } },
      ],
    });

    return TestBed.inject(OrganiseStore);
  }

  /** Like `make()`, but also hands back the mock's writable subscriptions
   *  signal so a test can simulate `SubscriptionsStore.load()` dropping a row
   *  out from under an existing selection. */
  function makeWithMutableSubscriptions(
    subscriptions: SubscriptionDto[] = SUBS,
    tags: TagDto[] = TAGS,
  ): { store: OrganiseStore; subsSignal: WritableSignal<SubscriptionDto[]> } {
    localStorage.clear();
    const subscriptionsSignal = signal(subscriptions);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        OrganiseStore,
        { provide: SubscriptionsStore, useValue: { subscriptions: subscriptionsSignal } },
        { provide: TagsStore, useValue: { tags: signal(tags) } },
      ],
    });

    return { store: TestBed.inject(OrganiseStore), subsSignal: subscriptionsSignal };
  }

  it('groups feeds under every tag and puts untagged last', () => {
    const store = make();

    const keys = store.groups().map((group) => group.key);
    expect(keys).toEqual([1, 2, 'untagged']);
    expect(store.groups()[0].subscriptions.map((subscription) => subscription.id)).toEqual([
      10, 12,
    ]);
    expect(store.groups()[2].subscriptions.map((subscription) => subscription.id)).toEqual([13]);
  });

  it('lists each root followed by its children, children one level deep', () => {
    const store = make(
      [],
      [tag(1, 'Reading', 0), { ...tag(2, 'Long reads', 1), parentId: 1 }, tag(3, 'Tech', 2)],
    );

    expect(store.groups().map((group) => [group.tag?.name ?? 'untagged', group.depth])).toEqual([
      ['Reading', 0],
      ['Long reads', 1],
      ['Tech', 0],
      ['untagged', 0],
    ]);
  });

  it('nests each child group under its parent in the group tree', () => {
    const store = make(
      [],
      [tag(1, 'Reading', 0), { ...tag(2, 'Long reads', 1), parentId: 1 }, tag(3, 'Tech', 2)],
    );

    expect(
      store.groupTree().map((node) => [node.group.key, node.children.map((child) => child.key)]),
    ).toEqual([
      [1, [2]],
      [3, []],
      ['untagged', []],
    ]);
  });

  it('lifts a child to the top of the group tree when a filter hides its parent', () => {
    const store = make(
      [sub(10, 'essay', { tagIds: [2] })],
      [tag(1, 'Reading', 0), { ...tag(2, 'Long reads', 1), parentId: 1 }],
    );

    store.titleFilter.set('essay');

    expect(
      store.groupTree().map((node) => [node.group.key, node.children.map((child) => child.key)]),
    ).toEqual([[2, []]]);
  });

  it('lists an empty saved feed, though the sidebar hides it', () => {
    const emptySaved = makeSubscription({
      id: 30,
      feedId: 30,
      title: 'Saved from feeds',
      sourceFormat: 'saved',
      entryCount: 0,
    });
    const store = make([...SUBS, emptySaved]);

    const untagged = store.groups().find((group) => group.key === 'untagged');
    expect(untagged?.subscriptions.map((subscription) => subscription.id)).toContain(30);
  });

  it('shows a feed with two tags in both groups', () => {
    const store = make();

    expect(store.groups()[0].subscriptions.map((subscription) => subscription.id)).toContain(12);
    expect(store.groups()[1].subscriptions.map((subscription) => subscription.id)).toContain(12);
  });

  it('selects a feed everywhere it appears, and counts it once', () => {
    const store = make();

    store.toggleFeed(12);

    expect(store.selectedIds().has(12)).toBe(true);
    expect(store.selectedCount()).toBe(1);
    // netzpolitik (12) carries both tags: Group 0 (Nachrichten) and Group 1
    // (Tech) each have one of two members selected -> 'some' in both, not
    // 'all', since heise/taz in each group stays untouched.
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

  /**
   * #659: "Expand all" means every group, not only the FILTERED groups(), so
   * clearing a search filter keeps the other groups expanded.
   */
  it('expandAll opens every group, not only the ones a filter is currently showing', () => {
    const store = make();
    store.titleFilter.set('heise'); // narrows groups() to Tech (2) only

    store.expandAll();
    store.titleFilter.set('');

    expect(store.isExpanded(1)).toBe(true);
    expect(store.isExpanded(2)).toBe(true);
    expect(store.isExpanded('untagged')).toBe(true);
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

  it('allExpanded is false until every group is open, then true', () => {
    const store = make();

    expect(store.allExpanded()).toBe(false);

    store.toggleGroup(1);
    store.toggleGroup(2);

    expect(store.allExpanded()).toBe(false); // 'untagged' is still closed

    store.toggleGroup('untagged');

    expect(store.allExpanded()).toBe(true);
  });

  it('toggleExpandAll expands from any state short of fully open, and collapses once everything is', () => {
    const store = make();

    // A mixed state -- one group open, the rest closed -- still reads as
    // "not fully expanded", so the toggle's next action is to open the rest.
    store.toggleGroup(1);
    store.toggleExpandAll();

    expect(store.isExpanded(1)).toBe(true);
    expect(store.isExpanded(2)).toBe(true);
    expect(store.isExpanded('untagged')).toBe(true);
    expect(store.allExpanded()).toBe(true);

    store.toggleExpandAll();

    expect(store.allExpanded()).toBe(false);
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

    expect(store.groups().map((group) => group.key)).toEqual([2]);
    expect(store.groups()[0].subscriptions.map((subscription) => subscription.id)).toEqual([11]);
  });

  it("finds an unrenamed Home by the Home's label", () => {
    const home = makeSubscription({
      id: 20,
      title: 'Home',
      social: { network: 'bluesky', accountId: 1, kind: 'home' },
    });
    const store = make([...SUBS, home]);

    store.titleFilter.set('@bsky');

    expect(store.filteredSubscriptions().map((subscription) => subscription.id)).toEqual([20]);
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

    expect(store.groups().map((group) => group.key)).toEqual(['untagged']);
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

    // Only heise (11) stays visible, so hidden (2) and visible-and-selected
    // (1) don't coincide -- catches a hiddenSelectedCount that counted the
    // wrong side of the filter.
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
        .map((subscription) => subscription.title)
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

    expect(store.listRows().map((subscription) => subscription.title)).toEqual([
      'heise',
      'netzpolitik',
      'taz',
      'Untagged feed',
    ]);
  });

  it('sorts the list view by last checked, descending puts the most recent first', () => {
    const store = make([
      makeSubscription({ id: 1, title: 'a', lastFetchedAt: '2026-01-01T00:00:00Z' }),
      makeSubscription({ id: 2, title: 'b', lastFetchedAt: '2026-03-01T00:00:00Z' }),
      makeSubscription({ id: 3, title: 'c', lastFetchedAt: null }),
    ]);
    store.view.set('list');
    store.sortField.set('checked');
    store.sortDirection.set('desc');

    expect(store.listRows().map((subscription) => subscription.id)).toEqual([2, 1, 3]);
  });

  it('sorts the list view by next refresh, ascending puts the soonest first', () => {
    const store = make([
      makeSubscription({ id: 1, title: 'a', nextFetchAt: '2026-05-01T00:00:00Z' }),
      makeSubscription({ id: 2, title: 'b', nextFetchAt: null }),
      makeSubscription({ id: 3, title: 'c', nextFetchAt: '2026-01-01T00:00:00Z' }),
    ]);
    store.view.set('list');
    store.sortField.set('due');
    store.sortDirection.set('asc');

    expect(store.listRows().map((subscription) => subscription.id)).toEqual([3, 1, 2]);
  });

  it('reverses the title order when the direction flips to descending', () => {
    const store = make([
      makeSubscription({ id: 1, title: 'apple' }),
      makeSubscription({ id: 2, title: 'cherry' }),
      makeSubscription({ id: 3, title: 'banana' }),
    ]);
    store.view.set('list');
    store.sortDirection.set('desc');

    expect(store.listRows().map((subscription) => subscription.title)).toEqual([
      'cherry',
      'banana',
      'apple',
    ]);
  });

  it('keeps a null timing value last whichever way the direction runs', () => {
    const subscriptions = [
      makeSubscription({ id: 1, title: 'a', lastFetchedAt: '2026-01-01T00:00:00Z' }),
      makeSubscription({ id: 2, title: 'b', lastFetchedAt: null }),
    ];
    const store = make(subscriptions);
    store.view.set('list');
    store.sortField.set('checked');

    store.sortDirection.set('asc');
    expect(store.listRows().map((subscription) => subscription.id)).toEqual([1, 2]);

    store.sortDirection.set('desc');
    expect(store.listRows().map((subscription) => subscription.id)).toEqual([1, 2]);
  });

  it('persists the collapsed groups under its own key, not the sidebar key', () => {
    const store = make();

    store.toggleGroup(1);

    expect(localStorage.getItem('sfr.tags.collapsed')).toBeNull();
    expect(localStorage.getItem('sfr.organise.expanded')).not.toBeNull();
  });

  /**
   * The bulk bar sends every selectedIds entry, and the backend 422s the
   * WHOLE request if one is stale (e.g. unsubscribed from its own row menu),
   * so a dropped subscription's id must be pruned from selection too (#659).
   */
  it('prunes a selected id once it disappears from the loaded subscriptions', () => {
    const { store, subsSignal } = makeWithMutableSubscriptions();
    store.toggleFeed(10);
    store.toggleFeed(11);
    store.toggleFeed(12);
    expect(store.selectedCount()).toBe(3);

    // Simulate unsubscribing 11 (heise) from its own row menu: subs.reloadQuietly()
    // reloads without it.
    subsSignal.set(SUBS.filter((subscription) => subscription.id !== 11));
    TestBed.tick();

    expect(store.selectedIds().has(11)).toBe(false);
    expect([...store.selectedIds()].sort()).toEqual([10, 12]);
    expect(store.selectedCount()).toBe(2);
    expect(store.hiddenSelectedCount()).toBe(0);
  });
});
