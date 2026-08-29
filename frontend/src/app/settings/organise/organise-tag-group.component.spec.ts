import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { OrganiseGroup, OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { LanguageService } from '../../core/language.service';
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };
const OTHER_TAG: TagDto = { id: 4, name: 'Nachrichten', color: null, icon: null, position: 1 };
const THIRD_TAG: TagDto = { id: 6, name: 'Kultur', color: null, icon: null, position: 2 };

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
      name: `Tag ${tagId}`,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

const SUB_A = feed(10, 'taz', [TECH.id]);
const SUB_B = feed(11, 'heise', [TECH.id]);
const SUB_WITH_TWO_TAGS = feed(12, 'netzpolitik', [TECH.id, OTHER_TAG.id]);

const GROUP: OrganiseGroup = {
  key: TECH.id,
  tag: TECH,
  subscriptions: [SUB_A, SUB_B],
  totalCount: 2,
};
const UNTAGGED_GROUP: OrganiseGroup = {
  key: 'untagged',
  tag: null,
  subscriptions: [SUB_A, SUB_B],
  totalCount: 2,
};
const THIRD_GROUP: OrganiseGroup = {
  key: THIRD_TAG.id,
  tag: THIRD_TAG,
  subscriptions: [],
  totalCount: 0,
};

describe('OrganiseTagGroupComponent', () => {
  let fixture: ComponentFixture<OrganiseTagGroupComponent>;

  const manage = {
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
    reorderTags: jest.fn(),
    retag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    editSubscription: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    unsubscribe: jest.fn(),
  };

  async function render(
    group: OrganiseGroup,
    options: { expanded?: boolean; selected?: number[]; coarse?: boolean } = {},
  ) {
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseTagGroupComponent, provideTranslocoTesting()],
        providers: [
          OrganiseStore,
          { provide: ManageActions, useValue: manage },
          { provide: LayoutService, useValue: { isCoarse: signal(options.coarse ?? false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: { subscriptions: signal([SUB_A, SUB_B, SUB_WITH_TWO_TAGS]) },
          },
          { provide: TagsStore, useValue: { tags: signal([TECH, OTHER_TAG, THIRD_TAG]) } },
        ],
      })
      .compileComponents();

    const store = TestBed.inject(OrganiseStore);
    if (options.expanded !== false) store.toggleGroup(group.key);
    for (const id of options.selected ?? []) store.toggleFeed(id);

    fixture = TestBed.createComponent(OrganiseTagGroupComponent);
    fixture.componentRef.setInput('group', group);
    fixture.detectChanges();

    return { store, manage, component: fixture.componentInstance };
  }

  it('shows the total feed count, not the filtered count', async () => {
    await render({ ...GROUP, subscriptions: [SUB_A], totalCount: 12 });

    expect(fixture.nativeElement.textContent).toContain('12');
  });

  /**
   * The `label` computed used to call TranslocoService.translate() directly
   * without reading a language signal — a one-shot read that never
   * re-evaluates on a switch (the same #411 trap reader-shell.component.ts
   * documents at line 340). The untagged group's name would then keep
   * reading "Untagged" in German while every transloco-pipe label in the
   * same header (e.g. the feed count) switched, visibly mixing languages
   * (#659 review).
   */
  it('translates the untagged group name on a language switch', async () => {
    await render(UNTAGGED_GROUP);

    expect(fixture.debugElement.query(By.css('.name')).nativeElement.textContent).toBe('Untagged');

    TestBed.inject(LanguageService).set('de');
    fixture.detectChanges();

    expect(fixture.debugElement.query(By.css('.name')).nativeElement.textContent).toBe('Ohne Tag');
  });

  it('renders no feed rows while the group is collapsed', async () => {
    await render(GROUP, { expanded: false });

    expect(fixture.debugElement.queryAll(By.css('app-organise-feed-row'))).toHaveLength(0);
  });

  it('marks the header checkbox indeterminate when some feeds are selected', async () => {
    await render(GROUP, { selected: [SUB_A.id] });

    const box = fixture.debugElement.query(By.css('[data-test="group-select"]')).nativeElement;
    expect(box.indeterminate).toBe(true);
    expect(box.checked).toBe(false);
  });

  it('selects every feed of the group from the header checkbox', async () => {
    const { store } = await render(GROUP);

    fixture.debugElement.query(By.css('[data-test="group-select"]')).nativeElement.click();

    expect(store.selectedCount()).toBe(GROUP.subscriptions.length);
  });

  it('reorders within the tag when a feed moves down', async () => {
    const { manage } = await render(GROUP);

    fixture.debugElement
      .queryAll(By.css('app-organise-feed-row'))[0]
      .componentInstance.moveDown.emit();

    expect(manage.reorderTagFeeds).toHaveBeenCalledWith(GROUP.tag!.id, [SUB_B.id, SUB_A.id]);
  });

  it('reorders the untagged list when the group is the untagged bucket', async () => {
    const { manage } = await render(UNTAGGED_GROUP);

    fixture.debugElement
      .queryAll(By.css('app-organise-feed-row'))[0]
      .componentInstance.moveDown.emit();

    expect(manage.reorderUntagged).toHaveBeenCalledWith([SUB_B.id, SUB_A.id]);
  });

  it('disables the feed arrows and drag under an active filter, and writes nothing', async () => {
    const { store, manage } = await render(GROUP);
    store.titleFilter.set('heise');
    fixture.detectChanges();

    const row = fixture.debugElement.queryAll(By.css('app-organise-feed-row'))[0];
    expect(row.componentInstance.reorderable()).toBe(false);
    expect(fixture.debugElement.query(By.css('[data-test="arrows-only"]'))).not.toBeNull();

    row.componentInstance.moveDown.emit();

    expect(manage.reorderTagFeeds).not.toHaveBeenCalled();
  });

  it('disables the untagged arrows under an active filter, and writes nothing', async () => {
    const { store, manage } = await render(UNTAGGED_GROUP);
    store.titleFilter.set('heise');
    fixture.detectChanges();

    const row = fixture.debugElement.queryAll(By.css('app-organise-feed-row'))[0];
    expect(row.componentInstance.reorderable()).toBe(false);

    row.componentInstance.moveDown.emit();

    expect(manage.reorderUntagged).not.toHaveBeenCalled();
  });

  it('ignores a same-group drop reorder under an active filter', async () => {
    const { store, manage, component } = await render(GROUP);
    store.titleFilter.set('heise');

    component.onFeedDropped({
      previousContainer: { data: GROUP },
      container: { data: GROUP },
      item: { data: SUB_A },
      previousIndex: 0,
      currentIndex: 1,
    } as never);

    expect(manage.reorderTagFeeds).not.toHaveBeenCalled();
  });

  it('moves a feed carrying two tags to a third tag that is neither of them', async () => {
    const { manage, component } = await render(THIRD_GROUP);

    // SUB_WITH_TWO_TAGS carries TECH (2) and OTHER_TAG (4). It is dragged out
    // of the TECH group (the source container) and dropped on THIRD_GROUP
    // (6) — a tag it did not previously carry. Only TECH, the source, must
    // be dropped; OTHER_TAG must survive untouched alongside the new tag.
    component.onFeedDropped({
      previousContainer: { data: GROUP },
      container: { data: THIRD_GROUP },
      item: { data: SUB_WITH_TWO_TAGS },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.retag).toHaveBeenCalledWith(SUB_WITH_TWO_TAGS, [OTHER_TAG.id, THIRD_TAG.id]);
  });

  it('removes a single-tag feed entirely when dropped on the untagged group', async () => {
    const { manage, component } = await render(UNTAGGED_GROUP);

    component.onFeedDropped({
      previousContainer: { data: GROUP },
      container: { data: UNTAGGED_GROUP },
      item: { data: SUB_A },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.retag).toHaveBeenCalledWith(SUB_A, []);
  });

  it('removes only the source tag when a two-tag feed is dropped on the untagged group', async () => {
    const { manage, component } = await render(UNTAGGED_GROUP);

    // SUB_WITH_TWO_TAGS carries TECH (2) and OTHER_TAG (4) and is dragged out
    // of the TECH group. Dropping on "Untagged" is single-tag removal, not a
    // clear: OTHER_TAG must survive.
    component.onFeedDropped({
      previousContainer: { data: GROUP },
      container: { data: UNTAGGED_GROUP },
      item: { data: SUB_WITH_TWO_TAGS },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.retag).toHaveBeenCalledWith(SUB_WITH_TWO_TAGS, [OTHER_TAG.id]);
  });

  it('ignores a dropped tag header instead of mistaking it for a feed', async () => {
    const { manage, component } = await render(THIRD_GROUP);

    // The feed list's own drop handler must never treat a non-feed payload
    // as a SubscriptionDto — `subscription.tags.map(...)` would throw, since
    // neither an OrganiseGroup nor a TagDto has a `tags` field. Tag-header
    // drags are handled by onHeaderDropped instead (see the tests below).
    component.onFeedDropped({
      previousContainer: { data: GROUP },
      container: { data: THIRD_GROUP },
      item: { data: GROUP },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.retag).not.toHaveBeenCalled();
    expect(manage.reorderTagFeeds).not.toHaveBeenCalled();
    expect(manage.reorderUntagged).not.toHaveBeenCalled();
  });

  /**
   * The tag header is a `cdkDrag` and its own `cdkDropList` accepts drops, but
   * nothing used to act on a dropped TAG — only a dropped feed. The design
   * spec calls for tag reordering by drag, same as feeds; ManageActions.
   * reorderTags(tagIds) is the write, and the order comes from the full,
   * unfiltered tags list (store.tags()), not the filtered group() (#659
   * review).
   */
  it('reorders tags when a tag header is dropped on another tag header', async () => {
    const { manage, component } = await render(THIRD_GROUP);

    // store.tags() = [TECH(2), OTHER_TAG(4), THIRD_TAG(6)]. Dragging TECH and
    // dropping it on THIRD_GROUP's header (6) moves it after THIRD_TAG.
    component.onHeaderDropped({
      previousContainer: { data: GROUP },
      container: { data: THIRD_GROUP },
      item: { data: TECH },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.reorderTags).toHaveBeenCalledWith([OTHER_TAG.id, THIRD_TAG.id, TECH.id]);
  });

  it('does nothing when a tag is dropped on its own header', async () => {
    const { manage, component } = await render(THIRD_GROUP);

    component.onHeaderDropped({
      previousContainer: { data: THIRD_GROUP },
      container: { data: THIRD_GROUP },
      item: { data: THIRD_TAG },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.reorderTags).not.toHaveBeenCalled();
  });

  it('ignores a tag-header reorder drop under an active filter, and writes nothing', async () => {
    const { store, manage, component } = await render(THIRD_GROUP);
    store.titleFilter.set('heise');

    component.onHeaderDropped({
      previousContainer: { data: GROUP },
      container: { data: THIRD_GROUP },
      item: { data: TECH },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.reorderTags).not.toHaveBeenCalled();
  });

  it('still adds the tag when a feed is dropped on the header (delegates to the feed drop)', async () => {
    const { manage, component } = await render(THIRD_GROUP);

    component.onHeaderDropped({
      previousContainer: { data: GROUP },
      container: { data: THIRD_GROUP },
      item: { data: SUB_WITH_TWO_TAGS },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.retag).toHaveBeenCalledWith(SUB_WITH_TWO_TAGS, [OTHER_TAG.id, THIRD_TAG.id]);
  });

  it('turns drag off on a coarse pointer, keeping the arrows', async () => {
    await render(GROUP, { coarse: true });

    const rows = fixture.debugElement.queryAll(By.css('app-organise-feed-row'));
    expect(rows[0].componentInstance.sortable()).toBe(false);
    expect(fixture.debugElement.query(By.css('[data-test="arrows-only"]'))).not.toBeNull();
  });

  /**
   * headerDragDisabled() has its own three off-switches (coarse pointer,
   * active filter, untagged group) plus the all-clear case — none of them
   * previously had a test of their own, only the write-guards inside
   * reorderDroppedTag() (#659 review). These assert on the DOM rather than on
   * headerDragDisabled()'s return value -- partly because the computed is
   * protected, but mostly because the DOM check is the stronger of the two: it
   * is what catches the template's `[cdkDragDisabled]="headerDragDisabled()"`
   * binding coming decoupled from the computed, which a check of the computed
   * alone sails straight past. CdkDrag reflects its `disabled` input as the
   * `cdk-drag-disabled` host class.
   */
  describe('headerDragDisabled', () => {
    it('disables the header drag on a coarse pointer', async () => {
      await render(GROUP, { coarse: true });

      const head = fixture.debugElement.query(By.css('.head')).nativeElement;
      expect(head.classList.contains('cdk-drag-disabled')).toBe(true);
    });

    it('disables the header drag under an active filter', async () => {
      const { store } = await render(GROUP);
      store.titleFilter.set('heise');
      fixture.detectChanges();

      const head = fixture.debugElement.query(By.css('.head')).nativeElement;
      expect(head.classList.contains('cdk-drag-disabled')).toBe(true);
    });

    it('disables the header drag for the untagged group, which has no tag to reorder', async () => {
      await render(UNTAGGED_GROUP);

      const head = fixture.debugElement.query(By.css('.head')).nativeElement;
      expect(head.classList.contains('cdk-drag-disabled')).toBe(true);
    });

    it('leaves the header drag enabled with a fine pointer, no filter, and a real tag', async () => {
      await render(GROUP);

      const head = fixture.debugElement.query(By.css('.head')).nativeElement;
      expect(head.classList.contains('cdk-drag-disabled')).toBe(false);
    });
  });

  it('does not reorder tags when a tag is dropped on the untagged group header', async () => {
    const { manage, component } = await render(UNTAGGED_GROUP);

    // The untagged group has no tag to reorder against — reorderDroppedTag's
    // `targetTag === null` guard must stop the write before it ever reaches
    // ManageActions (#659 review).
    component.onHeaderDropped({
      previousContainer: { data: GROUP },
      container: { data: UNTAGGED_GROUP },
      item: { data: TECH },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    expect(manage.reorderTags).not.toHaveBeenCalled();
  });
});
