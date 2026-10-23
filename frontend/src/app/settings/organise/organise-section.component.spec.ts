import { API_BASE_URL } from '../../core/api';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { computed, signal, WritableSignal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
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

  // canMoveTagUp/Down indexes the FILTERED groups() while moveTag() swaps
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

    it('lists a child tag directly under its parent in the tag filter', async () => {
      await renderWithMocks(TREE_SUBS, [NEWS, TECH, LOCAL]);
      fixture.debugElement.query(By.css('[data-test="tag-filter"]')).nativeElement.click();
      fixture.detectChanges();

      const labels = fixture.debugElement.queryAll(By.css('.pop label')).map((label) => ({
        name: (label.nativeElement as HTMLElement).textContent?.trim(),
        child: (label.nativeElement as HTMLElement).classList.contains('child'),
      }));

      expect(labels.slice(1)).toEqual([
        { name: 'News', child: false },
        { name: 'Local', child: true },
        { name: 'Tech', child: false },
      ]);
    });
  });

  describe('tag drag gap (#1708)', () => {
    // Tree top at 100; panels 80 px tall, 20 px apart: middles at 140, 240, 340 (+ untagged at 440).
    const TREE_TOP = 100;

    async function renderTags(tags: TagDto[] = [TECH, WORLD, ART]) {
      const rendered = await renderWithMocks(SUBS, tags);
      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP } as DOMRect);
      panels().forEach((panel, index) =>
        jest
          .spyOn(panel.nativeElement as HTMLElement, 'getBoundingClientRect')
          .mockReturnValue({ top: TREE_TOP + index * 100, height: 80 } as DOMRect),
      );
      return rendered;
    }

    const panels = () => fixture.debugElement.queryAll(By.directive(OrganiseTagGroupComponent));
    /** Per panel: the gap drawn before it, after it, or none. */
    const gapMarks = () =>
      panels().map((panel) => {
        const classes = (panel.nativeElement as HTMLElement).classList;
        if (classes.contains('gap-before')) return 'before';
        return classes.contains('gap-after') ? 'after' : '-';
      });

    function drag(fromPanel: number, clientY: number): void {
      panels()[fromPanel].triggerEventHandler('tagDragStarted');
      panels()[fromPanel].triggerEventHandler('tagDragMoved', clientY);
      fixture.detectChanges();
    }

    it('opens the gap above the first tag', async () => {
      await renderTags();

      drag(2, 110);

      expect(gapMarks()).toEqual(['before', '-', '-', '-']);
    });

    it('opens the gap below the last tag, above the untagged group', async () => {
      await renderTags();

      drag(0, 400);

      expect(gapMarks()).toEqual(['-', '-', 'after', '-']);
    });

    it('opens no gap beside the dragged tag itself', async () => {
      await renderTags();

      drag(1, 260);

      expect(gapMarks()).toEqual(['-', '-', '-', '-']);
    });

    it('measures against the tree, so a scrolled page keeps the same gap', async () => {
      await renderTags();
      panels()[2].triggerEventHandler('tagDragStarted');
      const tree = fixture.debugElement.query(By.css('.tree')).nativeElement as HTMLElement;
      jest.spyOn(tree, 'getBoundingClientRect').mockReturnValue({ top: TREE_TOP - 300 } as DOMRect);

      panels()[2].triggerEventHandler('tagDragMoved', 110 - 300);
      fixture.detectChanges();

      expect(gapMarks()).toEqual(['before', '-', '-', '-']);
    });

    it('moves the tag into the gap on drop and closes the gap', async () => {
      const { manage } = await renderTags();
      drag(0, 400);

      panels()[0].triggerEventHandler('tagDragEnded');
      fixture.detectChanges();

      expect(manage.reorderTags).toHaveBeenCalledWith([WORLD.id, ART.id, TECH.id]);
      expect(gapMarks()).toEqual(['-', '-', '-', '-']);
    });

    it('writes nothing when the tag is dropped where it was', async () => {
      const { manage } = await renderTags();
      drag(1, 260);

      panels()[1].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });

    it('writes nothing under an active filter', async () => {
      const { manage, store } = await renderTags();
      drag(0, 400);
      store.titleFilter.set('taz');

      panels()[0].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });

    it('writes nothing when the dragged tag is gone from the groups', async () => {
      const { manage } = await renderTags();
      const tags = TestBed.inject(TagsStore).tags as WritableSignal<TagDto[]>;
      tags.set([WORLD, ART]);

      drag(0, 400);
      panels()[0].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });

    it('writes nothing when a sibling has no panel yet', async () => {
      const { manage } = await renderTags();
      const tags = TestBed.inject(TagsStore).tags as WritableSignal<TagDto[]>;
      tags.set([TECH, WORLD, ART, { ...ART, id: 99, name: 'Fresh', position: 3 }]);

      drag(0, 400);
      panels()[0].triggerEventHandler('tagDragEnded');

      expect(manage.reorderTags).not.toHaveBeenCalled();
    });

    describe('child tags (#1736)', () => {
      /** Lays the panels out as they render: a root's panel holds its children's, above its own feeds. */
      function layOut(rects: readonly { top: number; height: number }[]): void {
        panels().forEach((panel, index) =>
          jest.spyOn(panel.nativeElement as HTMLElement, 'getBoundingClientRect').mockReturnValue({
            top: TREE_TOP + rects[index].top,
            height: rects[index].height,
          } as DOMRect),
        );
      }

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
      // 0 Reading (0–380, holding 1 Long reads 100–180 and 2 Essays 200–280), 3 Tech 400–480, 4 untagged 500–580.
      async function renderTree() {
        const rendered = await renderTags([READING, LONG_READS, ESSAYS, TECH]);
        layOut([
          { top: 0, height: 380 },
          { top: 100, height: 80 },
          { top: 200, height: 80 },
          { top: 400, height: 80 },
          { top: 500, height: 80 },
        ]);
        return rendered;
      }
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

      it('draws the gap for a dragged child only within its sibling range', async () => {
        await renderTree();

        drag(2, TREE_TOP + 120);
        expect(gapMarks()).toEqual(['-', 'before', '-', '-', '-']);

        drag(1, TREE_TOP + 340);
        expect(gapMarks()).toEqual(['-', '-', '-', '-', '-']);
      });

      it("draws the gap after the last child inside its parent's panel, above the parent's feeds", async () => {
        const { manage } = await renderTree();

        drag(1, TREE_TOP + 270);
        expect(gapMarks()).toEqual(['-', '-', 'after', '-', '-']);

        panels()[1].triggerEventHandler('tagDragEnded');
        expect(manage.reorderTags).toHaveBeenCalledWith([ESSAYS.id, LONG_READS.id]);
      });

      it('keeps the gaps above the first root and after the last root for a root drag', async () => {
        const { manage } = await renderTree();

        drag(3, TREE_TOP + 10);
        expect(gapMarks()).toEqual(['before', '-', '-', '-', '-']);

        drag(0, TREE_TOP + 460);
        expect(gapMarks()).toEqual(['-', '-', '-', 'after', '-']);

        panels()[0].triggerEventHandler('tagDragEnded');
        expect(manage.reorderTags).toHaveBeenCalledWith([TECH.id, READING.id]);
      });

      it('draws the gap after the last root past its children', async () => {
        // 0 Tech 0–80, 1 Reading 100–480 (holding 2 Long reads 200–280 and 3 Essays 300–380), 4 untagged 500–580.
        const { manage } = await renderTags([TECH, READING, LONG_READS, ESSAYS]);
        layOut([
          { top: 0, height: 80 },
          { top: 100, height: 380 },
          { top: 200, height: 80 },
          { top: 300, height: 80 },
          { top: 500, height: 80 },
        ]);

        drag(0, TREE_TOP + 400);
        expect(gapMarks()).toEqual(['-', 'after', '-', '-', '-']);
        const reading = panels()[1].nativeElement as HTMLElement;
        expect(reading.contains(panels()[3].nativeElement as HTMLElement)).toBe(true);

        panels()[0].triggerEventHandler('tagDragEnded');
        expect(manage.reorderTags).toHaveBeenCalledWith([READING.id, TECH.id]);
      });
    });
  });
});
