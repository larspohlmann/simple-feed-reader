import { API_BASE_URL } from '../../core/api';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal, WritableSignal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { CdkDropList, CdkDropListGroup } from '@angular/cdk/drag-drop';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseSectionComponent } from './organise-section.component';
import { OrganiseStore } from './organise.store';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { UnhealthyFeedRowComponent } from './health/unhealthy-feed-row.component';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { unhealthyFeeds } from '../../reader/feeds/feed-health';
import { TagsStore } from '../../reader/state/tags.store';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { LanguageService } from '../../core/i18n/language.service';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { makeSubscription } from '../../reader/testing/subscription.factory';

/** The real store derives `unhealthy`/`unhealthyCount` from `subscriptions`;
 *  the mock reproduces that so the health group renders off the same signal
 *  the tests set. */
function makeSubscriptionsStoreMock(initialSubscriptions: SubscriptionDto[]) {
  const subscriptions: WritableSignal<SubscriptionDto[]> = signal(initialSubscriptions);
  const unhealthy = computed(() => unhealthyFeeds(subscriptions()));
  return {
    subscriptions,
    loading: signal(false),
    load: jest.fn(),
    reloadQuietly: jest.fn(),
    unhealthy,
    unhealthyCount: computed(() => unhealthy().length),
  };
}

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0, parentId: null };
const WORLD: TagDto = {
  id: 4,
  name: 'World',
  color: null,
  icon: null,
  position: 1,
  parentId: null,
};
const ART: TagDto = { id: 6, name: 'Art', color: null, icon: null, position: 2, parentId: null };

const feed = (id: number, title: string, tagIds: number[]): SubscriptionDto =>
  makeSubscription({
    id,
    feedId: id,
    title,
    feedUrl: `https://feed-${id}.example/rss`,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: TECH.name,
      color: null,
      icon: null,
      position: index,
      parentId: null,
    })),
  });

const SUBS = [
  feed(10, 'taz', [TECH.id]),
  feed(11, 'heise', []),
  feed(12, 'netzpolitik', [TECH.id]),
];

describe('OrganiseSectionComponent', () => {
  let fixture: ComponentFixture<OrganiseSectionComponent>;

  const manage = {
    bulkAddTag: jest.fn(() => of(undefined)),
    bulkRemoveTag: jest.fn(() => of(undefined)),
    bulkSetFlags: jest.fn(() => of(undefined)),
    bulkUnsubscribe: jest.fn(() => of(true)),
    addFeed: jest.fn(() => of(undefined)),
    createTag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    editSubscription: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    setIncludeInSavedSearches: jest.fn(),
    unsubscribe: jest.fn(),
    retryFeed: jest.fn(),
    moveFeedToTag: jest.fn(),
    reorderTags: jest.fn(),
    moveTag: jest.fn(),
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
  };

  async function renderWithMocks(subscriptions: SubscriptionDto[] = SUBS, tags: TagDto[] = [TECH]) {
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.bulkAddTag.mockReturnValue(of(undefined));
    manage.bulkRemoveTag.mockReturnValue(of(undefined));
    manage.bulkSetFlags.mockReturnValue(of(undefined));
    manage.bulkUnsubscribe.mockReturnValue(of(true));
    manage.addFeed.mockReturnValue(of(undefined));
    manage.retryFeed.mockReturnValue(of(false));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          { provide: LanguageService, useValue: { lang: () => 'en' } },
          { provide: SubscriptionsStore, useValue: makeSubscriptionsStoreMock(subscriptions) },
          { provide: TagsStore, useValue: { tags: signal(tags), load: jest.fn() } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(OrganiseSectionComponent);
    fixture.detectChanges();
    const component = fixture.componentInstance;

    return { component, manage, store: component.store };
  }

  async function render(): Promise<OrganiseStore> {
    const { store } = await renderWithMocks();

    return store;
  }

  it('loads the saved-search networks, so a social feed is not marked hidden on a fresh load', async () => {
    await render();

    TestBed.inject(HttpTestingController)
      .expectOne('https://api.test/api/saved-searches')
      .flush({ savedSearches: [], includedNetworks: ['bluesky'] });

    expect([...TestBed.inject(SavedSearchesStore).includedNetworks()]).toEqual(['bluesky']);
  });

  it('hides the bulk bar at zero selection', async () => {
    await render();

    expect(fixture.debugElement.query(By.css('[data-test="bulk-bar"]'))).toBeNull();
  });

  it('offers the refresh-timing sort options in the list view', async () => {
    const store = await render();
    store.view.set('list');
    fixture.detectChanges();

    const values = fixture.debugElement
      .queryAll(By.css('[data-test="sort"] option'))
      .map((option) => (option.nativeElement as HTMLOptionElement).value);
    expect(values).toEqual(['title', 'added', 'checked', 'updated', 'due']);
  });

  it('flips the sort direction when the direction toggle is pressed', async () => {
    const store = await render();
    store.view.set('list');
    fixture.detectChanges();
    expect(store.sortDirection()).toBe('asc');

    fixture.debugElement.query(By.css('[data-test="sort-direction"]')).nativeElement.click();

    expect(store.sortDirection()).toBe('desc');
  });

  it('shows the exact count once something is selected', async () => {
    const store = await render();

    store.toggleFeed(10);
    fixture.detectChanges();

    expect(
      fixture.debugElement.query(By.css('[data-test="bulk-count"]')).nativeElement.textContent,
    ).toContain('1');
  });

  it('names how many selected feeds the filter hides', async () => {
    const store = await render();
    store.toggleFeed(10);
    store.toggleFeed(11);
    store.toggleFeed(12);

    store.titleFilter.set('heise');
    fixture.detectChanges();

    // Only heise (11) stays visible, so hidden (2) and visible-and-selected
    // (1) don't coincide -- catches a hiddenSelectedCount that counted the
    // wrong side of the filter (mirrors organise.store.spec.ts's fixture).
    expect(
      fixture.debugElement.query(By.css('[data-test="bulk-hidden"]')).nativeElement.textContent,
    ).toContain('2');
  });

  it('select all takes exactly the visible rows', async () => {
    const store = await render();
    store.titleFilter.set('heise');
    fixture.detectChanges();

    fixture.debugElement.query(By.css('[data-test="select-all"]')).nativeElement.click();

    expect([...store.selectedIds()]).toEqual([11]);
  });

  it('the expand toggle reads "Expand all" collapsed, flips to "Collapse all" once every group is open', async () => {
    await render();

    const toggle = () =>
      fixture.debugElement.query(By.css('[data-test="expand-toggle"]')).nativeElement;

    expect((toggle() as HTMLElement).textContent).toContain('Expand all');

    toggle().click();
    fixture.detectChanges();

    expect((toggle() as HTMLElement).textContent).toContain('Collapse all');

    toggle().click();
    fixture.detectChanges();

    expect((toggle() as HTMLElement).textContent).toContain('Expand all');
  });

  it('renders no arrows and no handles in the list view', async () => {
    const store = await render();

    store.view.set('list');
    fixture.detectChanges();

    expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).toBeNull();
    expect(fixture.debugElement.query(By.css('[data-test="drag-handle"]'))).toBeNull();
  });

  it('disables the bulk bar while a write is in flight', async () => {
    const store = await render();
    store.toggleFeed(10);
    store.busy.set(true);
    fixture.detectChanges();

    expect(
      fixture.debugElement
        .query(By.css('[data-test="bulk-unsubscribe"]'))
        .componentInstance.disabled(),
    ).toBe(true);
  });

  it('clears the selection after an unsubscribe but keeps it after a tag write', async () => {
    const { component, manage } = await renderWithMocks();
    manage.bulkAddTag.mockReturnValue(of(undefined));
    manage.bulkUnsubscribe.mockReturnValue(of(true));
    component.store.toggleFeed(10);

    component.applyTag(TECH, 'add');
    expect(component.store.selectedCount()).toBe(1);

    component.unsubscribeSelected();
    expect(component.store.selectedCount()).toBe(0);
  });

  it('keeps the selection when a bulk write fails, and shows the error', async () => {
    const { component, manage } = await renderWithMocks();
    manage.bulkAddTag.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 422 })));
    component.store.toggleFeed(10);

    component.applyTag(TECH, 'add');

    expect(component.store.selectedCount()).toBe(1);
    expect(component.error()).not.toBeNull();
  });

  it('opens the add-feed dialog from the page header', async () => {
    const { manage } = await renderWithMocks();

    fixture.debugElement.query(By.css('[data-test="add-feed"]')).nativeElement.click();

    expect(manage.addFeed).toHaveBeenCalled();
  });

  // The store keeps selection independent of the view, but only a click
  // through the actual template button proves the view-switch control itself
  // does not also clear it.
  it('keeps the selection when the header switches from tree to list view', async () => {
    const store = await render();
    store.toggleFeed(10);

    fixture.debugElement.query(By.css('[data-test="view-list"]')).nativeElement.click();
    fixture.detectChanges();

    expect(store.selectedCount()).toBe(1);
  });

  it('narrows the list to untagged feeds through the tag filter', async () => {
    const store = await render();

    store.tagFilter.set(new Set(['untagged']));
    fixture.detectChanges();

    expect(store.filteredSubscriptions().map((subscription) => subscription.id)).toEqual([11]);
  });

  // Selection and filtering are separate state on the store; a bulk write must
  // reach every SELECTED feed, including one the current filter hides. Acting
  // on filteredSubscriptions()/visibleIds() instead would silently drop it.
  it('applies a bulk tag to every selected feed, including one the filter is hiding', async () => {
    const { component, manage } = await renderWithMocks();
    component.store.toggleFeed(10);
    component.store.toggleFeed(11);
    component.store.titleFilter.set('taz'); // hides 11 from view, not from selection

    component.applyTag(TECH, 'add');

    expect(manage.bulkAddTag).toHaveBeenCalledWith([10, 11], TECH);
  });

  it('sets flags on every selected feed, including one the filter is hiding', async () => {
    const { component, manage } = await renderWithMocks();
    component.store.toggleFeed(10);
    component.store.toggleFeed(11);
    component.store.titleFilter.set('taz');

    component.setFlags({ includeInAllItems: false });

    expect(manage.bulkSetFlags).toHaveBeenCalledWith([10, 11], { includeInAllItems: false });
  });

  it('offers six visibility commands, the saved-search ones setting their own flag', async () => {
    const { component, manage } = await renderWithMocks();
    component.store.toggleFeed(10);
    fixture.detectChanges();
    const root = fixture.debugElement;
    root.query(By.css('[data-test="visibility"]')).nativeElement.click();
    fixture.detectChanges();

    const items = root.queryAll(By.css('.pop [role="menuitem"]'));
    expect(items.map((item) => item.nativeElement.textContent.trim())).toEqual([
      'Show in All items',
      'Hide from All items',
      'Show in For you',
      'Hide from For you',
      'Show in saved searches',
      'Hide from saved searches',
    ]);

    items[5].nativeElement.click();
    expect(manage.bulkSetFlags).toHaveBeenCalledWith([10], { includeInSavedSearches: false });
    root.query(By.css('[data-test="visibility"]')).nativeElement.click();
    fixture.detectChanges();
    root.queryAll(By.css('.pop [role="menuitem"]'))[4].nativeElement.click();
    expect(manage.bulkSetFlags).toHaveBeenCalledWith([10], { includeInSavedSearches: true });
  });

  // The tree view's tag headers reorder tags themselves via the up/down arrows
  // on OrganiseTagGroupComponent (moveTagUp/moveTagDown), which this component
  // turns into a full reordered id list for ManageActions.reorderTags.
  it('swaps two adjacent tag ids when a header arrow moves a tag down', async () => {
    const NEWS: TagDto = {
      id: 3,
      name: 'News',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.addFeed.mockReturnValue(of(undefined));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          { provide: LanguageService, useValue: { lang: () => 'en' } },
          { provide: SubscriptionsStore, useValue: makeSubscriptionsStoreMock(SUBS) },
          { provide: TagsStore, useValue: { tags: signal([TECH, NEWS]), load: jest.fn() } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(OrganiseSectionComponent);
    fixture.detectChanges();

    const groups = fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));
    expect(groups.length).toBeGreaterThanOrEqual(2);
    // TECH (id 2) is first, NEWS (id 3) second: move TECH down past NEWS.
    (groups[0].componentInstance as OrganiseTagGroupComponent).moveTagDown.emit();

    expect(manage.reorderTags).toHaveBeenCalledWith([3, 2]);
  });

  // canMoveTagUp/Down indexes the FILTERED groups() while stepTag() swaps
  // within the full tags() list -- that mismatch could silently swap with a
  // tag the user can't see, so both must refuse while a filter is active.
  it('disables tag reordering under an active filter, and writes nothing', async () => {
    const NEWS: TagDto = {
      id: 3,
      name: 'News',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    const subscriptionsWithNews = [...SUBS.slice(0, 1), feed(11, 'heise', [NEWS.id]), SUBS[2]];
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.addFeed.mockReturnValue(of(undefined));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          provideRouter([]),
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: 'https://api.test' },
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          { provide: LanguageService, useValue: { lang: () => 'en' } },
          {
            provide: SubscriptionsStore,
            useValue: makeSubscriptionsStoreMock(subscriptionsWithNews),
          },
          { provide: TagsStore, useValue: { tags: signal([TECH, NEWS]), load: jest.fn() } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(OrganiseSectionComponent);
    fixture.detectChanges();
    const component = fixture.componentInstance;

    // Both TECH and NEWS stay visible and adjacent under this filter, so
    // canMoveTagDown would be true on TECH if the filter guard were missing.
    component.store.tagFilter.set(new Set([TECH.id, NEWS.id]));
    fixture.detectChanges();

    const groups = fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));
    expect(groups.length).toBe(2);
    expect((groups[0].componentInstance as OrganiseTagGroupComponent).canMoveTagDown()).toBe(false);

    (groups[0].componentInstance as OrganiseTagGroupComponent).moveTagDown.emit();

    expect(manage.reorderTags).not.toHaveBeenCalled();
  });

  describe('unhealthy-feeds group', () => {
    it('renders a row per unhealthy feed above the main list, and wires retry/unsubscribe', async () => {
      const dead = makeSubscription({
        id: 20,
        feedId: 20,
        title: 'zombie-blog',
        feedUrl: 'https://feed-20.example/rss',
        status: 'gone',
      });
      const { manage } = await renderWithMocks([...SUBS, dead]);

      const rows = fixture.debugElement.queryAll(By.directive(UnhealthyFeedRowComponent));
      expect(rows.length).toBe(1);

      const row = rows[0].componentInstance as UnhealthyFeedRowComponent;
      row.retry.emit();
      row.unsubscribe.emit();

      expect(manage.retryFeed).toHaveBeenCalledWith(dead);
      expect(manage.unsubscribe).toHaveBeenCalledWith(dead);
    });

    it('shows nothing when every feed is active', async () => {
      await renderWithMocks(SUBS);

      expect(fixture.debugElement.query(By.directive(UnhealthyFeedRowComponent))).toBeNull();
    });
  });

  describe('child tags in the tree (#1736)', () => {
    const NEWS: TagDto = {
      id: 20,
      name: 'News',
      color: null,
      icon: null,
      position: 0,
      parentId: null,
    };
    const LOCAL: TagDto = {
      id: 21,
      name: 'Local',
      color: null,
      icon: null,
      position: 0,
      parentId: 20,
    };
    const TREE_SUBS = [feed(30, 'news-feed', [NEWS.id]), feed(31, 'local-feed', [LOCAL.id])];

    it("lists a parent's child panels between its heading and its own feeds", async () => {
      const { store } = await renderWithMocks(TREE_SUBS, [NEWS, LOCAL]);
      store.expandAll();
      fixture.detectChanges();

      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      const order = [...tree.querySelectorAll('.head .name, app-organise-feed-row .title')].map(
        (element) => element.textContent?.trim(),
      );

      expect(order).toEqual(['News', 'Local', 'local-feed', 'news-feed', 'Untagged']);
    });

    it("connects every panel's drop lists through the tree's drop-list group", async () => {
      await renderWithMocks(TREE_SUBS, [NEWS, LOCAL]);
      fixture.componentInstance.store.expandAll();
      fixture.detectChanges();

      const group = fixture.debugElement
        .query(By.css('.tree'))
        .injector.get(CdkDropListGroup<CdkDropList>);
      const lists = fixture.debugElement
        .queryAll(By.directive(CdkDropList))
        .map((list) => list.injector.get(CdkDropList));

      expect(lists).toHaveLength(6);
      expect([...group._items]).toEqual(expect.arrayContaining(lists));
      expect(group._items.size).toBe(lists.length);
    });

    it('lists a child tag directly under its parent in the tag filter', async () => {
      await renderWithMocks(TREE_SUBS, [NEWS, TECH, LOCAL]);
      fixture.debugElement.query(By.css('[data-test="tag-filter"]')).nativeElement.click();
      fixture.detectChanges();

      const labels = fixture.debugElement.queryAll(By.css('.pop label')).map((label) => ({
        name: (label.nativeElement as HTMLElement).textContent?.trim(),
        depth: (label.nativeElement as HTMLElement).style.getPropertyValue('--depth'),
      }));

      expect(labels.slice(1)).toEqual([
        { name: 'News', depth: '0' },
        { name: 'Local', depth: '1' },
        { name: 'Tech', depth: '0' },
      ]);
    });

    it("renders a grandchild's panel inside its parent's children", async () => {
      const BLOCKS: TagDto = { ...LOCAL, id: 22, name: 'Blocks', parentId: LOCAL.id };
      await renderWithMocks([feed(32, 'block-feed', [BLOCKS.id])], [NEWS, LOCAL, BLOCKS]);

      const nested = fixture.nativeElement.querySelector(
        '.tree > app-organise-tag-group .children > app-organise-tag-group .children > app-organise-tag-group',
      ) as HTMLElement | null;

      expect(nested?.querySelector('.head .name')?.textContent?.trim()).toBe('Blocks');
    });
  });

  describe('tag drag (#1708, #1765)', () => {
    // Tree top at 100; header rows 40 tall at 100 + index * 100 in screen order; a panel's foot 80 below its top.
    const TREE_TOP = 100;
    const panels = () => fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));

    /** CDK lifts the dragged `.head` to the page top before `started`, so only its drop wrapper keeps the slot. */
    function mockScreenLayout(): void {
      const host = fixture.nativeElement as HTMLElement;
      const tree = host.querySelector('.tree') as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP } as DOMRect);
      host.querySelectorAll<HTMLElement>('app-organise-tag-group').forEach((panel, index) => {
        const top = TREE_TOP + index * 100;
        jest
          .spyOn(panel, 'getBoundingClientRect')
          .mockReturnValue({ top, bottom: top + 80 } as DOMRect);
        const headDrop = panel.querySelector(':scope > .panel > .head-drop') as HTMLElement;
        jest
          .spyOn(headDrop, 'getBoundingClientRect')
          .mockReturnValue({ top, bottom: top + 40 } as DOMRect);
        jest
          .spyOn(headDrop.querySelector('.head') as HTMLElement, 'getBoundingClientRect')
          .mockReturnValue({ top: 0, bottom: 40 } as DOMRect);
      });
    }

    async function renderTags(tags: TagDto[] = [TECH, WORLD, ART]) {
      const rendered = await renderWithMocks(SUBS, tags);
      mockScreenLayout();
      return rendered;
    }

    /** Per panel: the gap drawn before it, after it, or none. */
    const gapMarks = () =>
      panels().map((panel) => {
        const classes = (panel.nativeElement as HTMLElement).classList;
        if (classes.contains('gap-before')) return 'before';
        return classes.contains('gap-after') ? 'after' : '-';
      });
    const nestMarks = () =>
      panels().map((panel) =>
        (panel.nativeElement as HTMLElement).querySelector(
          ':scope > .panel > .head-drop.nest-target',
        )
          ? 'nest'
          : '-',
      );

    function drag(fromPanel: number, clientY: number): void {
      panels()[fromPanel].triggerEventHandler('tagDragStarted');
      panels()[fromPanel].triggerEventHandler('tagDragMoved', clientY);
      fixture.detectChanges();
    }

    it('opens the gap above the first tag', async () => {
      await renderTags();

      drag(2, 102);

      expect(gapMarks()).toEqual(['before', '-', '-', '-']);
    });

    it('opens the gap below the last tag, above the untagged group', async () => {
      await renderTags();

      drag(0, 395);

      expect(gapMarks()).toEqual(['-', '-', 'after', '-']);
    });

    it('measures the header rows, not the dragged header CDK has lifted to the page top', async () => {
      await renderTags();

      drag(0, 302);

      expect(gapMarks()).toEqual(['-', '-', 'before', '-']);
    });

    describe('when the panel query order differs from the screen order', () => {
      const panelOf = (tag: TagDto) =>
        panels().find(
          (panel) =>
            (panel.componentInstance as OrganiseTagGroupComponent).group().tag?.id === tag.id,
        )!;

      // Re-ordering moves the stamped panels on screen; the query keeps the order they were created in.
      async function renderReordered() {
        const rendered = await renderTags();
        TestBed.inject(TagsStore).tags.set([
          { ...ART, position: 0 },
          { ...TECH, position: 1 },
          { ...WORLD, position: 2 },
        ]);
        fixture.detectChanges();
        mockScreenLayout();
        return rendered;
      }

      function dragFrom(tag: TagDto, clientY: number): void {
        panelOf(tag).triggerEventHandler('tagDragStarted');
        panelOf(tag).triggerEventHandler('tagDragMoved', clientY);
        fixture.detectChanges();
      }

      it('nests under the visually last root instead of reading it as the list end', async () => {
        const { manage } = await renderReordered();

        dragFrom(TECH, 320);
        panelOf(TECH).triggerEventHandler('tagDragEnded');

        expect(manage.moveTag).toHaveBeenCalledWith(expect.objectContaining({ id: TECH.id }), {
          parentId: WORLD.id,
          order: [TECH.id],
          mark: { kind: 'nest', tagId: WORLD.id },
        });
      });

      it('opens the gap below the visually last root', async () => {
        const { manage } = await renderReordered();

        dragFrom(TECH, 395);
        panelOf(TECH).triggerEventHandler('tagDragEnded');

        expect(manage.moveTag).toHaveBeenCalledWith(expect.objectContaining({ id: TECH.id }), {
          parentId: null,
          order: [ART.id, WORLD.id, TECH.id],
          mark: { kind: 'after', tagId: WORLD.id },
        });
      });
    });

    it('opens no gap beside the dragged tag itself', async () => {
      await renderTags();

      drag(1, 202);

      expect(gapMarks()).toEqual(['-', '-', '-', '-']);
    });

    it('measures against the tree, so a scrolled page keeps the same gap', async () => {
      await renderTags();
      panels()[2].triggerEventHandler('tagDragStarted');
      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP - 300 } as DOMRect);

      panels()[2].triggerEventHandler('tagDragMoved', 102 - 300);
      fixture.detectChanges();

      expect(gapMarks()).toEqual(['before', '-', '-', '-']);
    });

    it('highlights the header a tag will nest under', async () => {
      await renderTags();

      drag(2, 120);

      expect(nestMarks()).toEqual(['nest', '-', '-', '-']);
      expect(gapMarks()).toEqual(['-', '-', '-', '-']);
    });

    it('moves the tag on drop through moveTag and clears the cue', async () => {
      const { manage } = await renderTags();
      drag(2, 120);

      panels()[2].triggerEventHandler('tagDragEnded');
      fixture.detectChanges();

      expect(manage.moveTag).toHaveBeenCalledWith(ART, {
        parentId: TECH.id,
        order: [ART.id],
        mark: { kind: 'nest', tagId: TECH.id },
      });
      expect(nestMarks()).toEqual(['-', '-', '-', '-']);
    });

    it('writes nothing when the tag is dropped where it was', async () => {
      const { manage } = await renderTags();
      drag(1, 202);

      panels()[1].triggerEventHandler('tagDragEnded');

      expect(manage.moveTag).not.toHaveBeenCalled();
    });

    describe('child tags (#1736)', () => {
      const READING: TagDto = {
        id: 7,
        name: 'Reading',
        color: null,
        icon: null,
        position: 0,
        parentId: null,
      };
      const LONG_READS: TagDto = {
        id: 8,
        name: 'Long reads',
        color: null,
        icon: null,
        position: 0,
        parentId: 7,
      };
      const ESSAYS: TagDto = {
        id: 9,
        name: 'Essays',
        color: null,
        icon: null,
        position: 1,
        parentId: 7,
      };
      const TECH_AFTER_READING: TagDto = { ...TECH, position: 1 };
      // Panels: 0 Reading, 1 Long reads, 2 Essays, 3 Tech, 4 untagged; headers at 0, 100, 200, 300 below the tree top.
      const renderTree = () => renderTags([READING, LONG_READS, ESSAYS, TECH_AFTER_READING]);
      const groupAt = (index: number) =>
        panels()[index].componentInstance as OrganiseTagGroupComponent;

      it('moves a child only among its siblings with the arrows', async () => {
        const { manage } = await renderTree();

        expect(groupAt(1).canMoveTagUp()).toBe(false);
        expect(groupAt(1).canMoveTagDown()).toBe(true);
        expect(groupAt(2).canMoveTagDown()).toBe(false);

        groupAt(1).moveTagDown.emit();

        expect(manage.reorderTags).toHaveBeenCalledWith([ESSAYS.id, LONG_READS.id]);
      });

      it('moves a root past the next root, skipping the children between them', async () => {
        const { manage } = await renderTree();

        groupAt(0).moveTagDown.emit();

        expect(manage.reorderTags).toHaveBeenCalledWith([TECH.id, READING.id]);
      });

      it("un-nests a child dropped on a top-level header's top edge", async () => {
        const { manage } = await renderTree();
        drag(2, TREE_TOP + 302);

        panels()[2].triggerEventHandler('tagDragEnded');

        expect(manage.moveTag).toHaveBeenCalledWith(ESSAYS, {
          parentId: null,
          order: [READING.id, ESSAYS.id, TECH.id],
          mark: { kind: 'before', tagId: TECH.id },
        });
      });

      it("moves a child to another root dropped on that root's middle", async () => {
        const { manage } = await renderTree();
        drag(1, TREE_TOP + 320);
        expect(nestMarks()).toEqual(['-', '-', '-', 'nest', '-']);

        panels()[1].triggerEventHandler('tagDragEnded');

        expect(manage.moveTag).toHaveBeenCalledWith(LONG_READS, {
          parentId: TECH.id,
          order: [LONG_READS.id],
          mark: { kind: 'nest', tagId: TECH.id },
        });
      });
    });
  });
});
