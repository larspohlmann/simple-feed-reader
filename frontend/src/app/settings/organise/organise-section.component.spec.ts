import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseSectionComponent } from './organise-section.component';
import { OrganiseStore } from './organise.store';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };

const feed = (id: number, title: string, tagIds: number[]): SubscriptionDto =>
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
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: TECH.name,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

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
    unsubscribe: jest.fn(),
    retag: jest.fn(),
    reorderTags: jest.fn(),
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
  };

  async function renderWithMocks() {
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.bulkAddTag.mockReturnValue(of(undefined));
    manage.bulkRemoveTag.mockReturnValue(of(undefined));
    manage.bulkSetFlags.mockReturnValue(of(undefined));
    manage.bulkUnsubscribe.mockReturnValue(of(true));
    manage.addFeed.mockReturnValue(of(undefined));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: { subscriptions: signal(SUBS), loading: signal(false), load: jest.fn() },
          },
          { provide: TagsStore, useValue: { tags: signal([TECH]), load: jest.fn() } },
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

  it('hides the bulk bar at zero selection', async () => {
    await render();

    expect(fixture.debugElement.query(By.css('[data-test="bulk-bar"]'))).toBeNull();
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

    // Only heise (11) stays visible; taz (10) and netzpolitik (12) are
    // hidden. Selecting 3 against 1 visible keeps hidden (2) and
    // visible-and-selected (1) from coinciding, so a hiddenSelectedCount
    // that accidentally counted the wrong side of the filter would be
    // caught here (mirrors organise.store.spec.ts's own fixture).
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

    expect(store.filteredSubscriptions().map((s) => s.id)).toEqual([11]);
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

  // The tree view's tag headers reorder tags themselves via the up/down arrows
  // on OrganiseTagGroupComponent (moveTagUp/moveTagDown), which this component
  // turns into a full reordered id list for ManageActions.reorderTags.
  it('swaps two adjacent tag ids when a header arrow moves a tag down', async () => {
    const NEWS: TagDto = { id: 3, name: 'News', color: null, icon: null, position: 1 };
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.addFeed.mockReturnValue(of(undefined));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: { subscriptions: signal(SUBS), loading: signal(false), load: jest.fn() },
          },
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

  // canMoveTagUp/Down index store.groups() — the FILTERED list — while
  // moveTag() swaps within the full, unfiltered tags() list. Under a filter
  // that mismatch can silently swap a tag's position with one the user
  // cannot even see, so both the arrow and moveTag() itself must refuse
  // while a filter is active, even when two tag groups stay adjacent.
  it('disables tag reordering under an active filter, and writes nothing', async () => {
    const NEWS: TagDto = { id: 3, name: 'News', color: null, icon: null, position: 1 };
    const subsWithNews = [...SUBS.slice(0, 1), feed(11, 'heise', [NEWS.id]), SUBS[2]];
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.addFeed.mockReturnValue(of(undefined));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
          { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: {
              subscriptions: signal(subsWithNews),
              loading: signal(false),
              load: jest.fn(),
            },
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
});
