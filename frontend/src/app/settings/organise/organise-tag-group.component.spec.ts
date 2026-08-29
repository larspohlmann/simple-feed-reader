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
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };
const OTHER_TAG: TagDto = { id: 4, name: 'Nachrichten', color: null, icon: null, position: 1 };

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

describe('OrganiseTagGroupComponent', () => {
  let fixture: ComponentFixture<OrganiseTagGroupComponent>;

  const manage = {
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
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
          { provide: TagsStore, useValue: { tags: signal([TECH, OTHER_TAG]) } },
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

  it('moves a feed out of the source tag when it is dropped on another group', async () => {
    const { manage, component } = await render(GROUP);

    component.onFeedDropped({
      previousContainer: { data: { key: 4, tag: OTHER_TAG } },
      container: { data: GROUP },
      item: { data: SUB_WITH_TWO_TAGS },
      previousIndex: 0,
      currentIndex: 0,
    } as never);

    // tag 4 dropped, this group's tag added — a move, not a copy.
    expect(manage.retag).toHaveBeenCalledWith(SUB_WITH_TWO_TAGS, [GROUP.tag!.id]);
  });

  it('clears every tag when a feed is dropped on the untagged group', async () => {
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

  it('turns drag off on a coarse pointer, keeping the arrows', async () => {
    await render(GROUP, { coarse: true });

    const rows = fixture.debugElement.queryAll(By.css('app-organise-feed-row'));
    expect(rows[0].componentInstance.sortable()).toBe(false);
    expect(fixture.debugElement.query(By.css('[data-test="arrows-only"]'))).not.toBeNull();
  });
});
