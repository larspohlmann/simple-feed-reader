import { TestBed } from '@angular/core/testing';
import { Subject, of } from 'rxjs';
import { computed, signal } from '@angular/core';
import { SOCIAL_NETWORKS, SocialNetworkName } from '../../../core/social-network';
import { NETWORK_EXCLUSION_STORES } from '../../state/network-exclusions.store';
import { NetworkSurfaceSwitches } from '../../state/network-surface-switches.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { TagActions } from '../../feeds/manage/tag-actions.service';
import { heldUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { SocialFeedsPicker } from '../../feeds/social-picker/social-feeds-picker.service';
import { socialFeedGroup, SocialSection } from '../../state/social-sections';
import { TagsStore } from '../../state/tags.store';
import { tagsStoreStub } from '../../testing/tags-store.stub';
import { SubscriptionDto, TagDto } from '../../models';
import {
  ACTION_SHEET_DIVIDER,
  ActionSheetCheck,
  ActionSheetData,
} from '../../../shared/action-sheet/action-sheet.component';
import { makeSubscription } from '../../testing/subscription.factory';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { INCLUDED_EVERYWHERE } from '../../tag-inclusion';
import { ExclusionSurface, INCLUSION_FLAG } from '../../feed-exclusions';

describe('SidebarRowActions', () => {
  it("titles an unrenamed Home's feed sheet with the Home's label", () => {
    const open = jest.fn(() => of(undefined));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        SidebarRowActions,
        { provide: ActionSheet, useValue: { open } },
        { provide: ManageActions, useValue: {} },
        { provide: TagActions, useValue: {} },
        { provide: NetworkSurfaceSwitches, useValue: {} },
        { provide: SubscriptionsStore, useValue: { subscriptions: signal([]) } },
        { provide: TagsStore, useValue: tagsStoreStub(signal<TagDto[]>([])) },
      ],
    });
    TestBed.inject(SidebarRowActions).openFeedSheet(heldUnrenamedHome(1));

    expect(open).toHaveBeenCalledWith(expect.objectContaining({ title: 'Home@bsky' }));
  });

  describe('the feed sheet', () => {
    const feed = makeSubscription({ id: 5, title: 'Feed five' });
    const subscriptions = signal<SubscriptionDto[]>([feed]);
    const setInclusion = jest.fn(
      (subscription: SubscriptionDto, surface: ExclusionSurface, included: boolean) =>
        subscriptions.update((current) =>
          current.map((listed) =>
            listed.id === subscription.id
              ? { ...listed, [INCLUSION_FLAG[surface]]: included }
              : listed,
          ),
        ),
    );
    const sheetOpen = jest.fn();

    beforeEach(() => {
      subscriptions.set([feed]);
      setInclusion.mockClear();
      sheetOpen.mockReset();
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: { setInclusion } },
          { provide: TagActions, useValue: {} },
          { provide: NetworkSurfaceSwitches, useValue: {} },
          { provide: SubscriptionsStore, useValue: { subscriptions } },
          { provide: TagsStore, useValue: tagsStoreStub(signal<TagDto[]>([])) },
        ],
      });
    });

    const checksOf = (data: ActionSheetData): ActionSheetCheck[] =>
      data.actions.filter((item): item is ActionSheetCheck => 'toggle' in item);

    const openedChecks = (): ActionSheetCheck[] => {
      sheetOpen.mockReturnValue(new Subject<string | undefined>());
      TestBed.inject(SidebarRowActions).openFeedSheet(feed);
      return checksOf(sheetOpen.mock.calls[0][0]);
    };

    it('offers edit, the four switches between dividers, and unsubscribe', () => {
      openedChecks();

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'Feed five',
        actions: [
          { id: 'edit', label: 'Edit feed' },
          ACTION_SHEET_DIVIDER,
          expect.objectContaining({ id: 'allItems', label: 'Show in All items' }),
          expect.objectContaining({ id: 'forYou', label: 'Show in For You' }),
          expect.objectContaining({ id: 'savedSearches', label: 'Show in saved searches' }),
          expect.objectContaining({ id: 'trending', label: 'Show in Trending' }),
          ACTION_SHEET_DIVIDER,
          { id: 'unsubscribe', label: 'Unsubscribe', danger: true },
        ],
      });
    });

    it('flips from the live subscription, so a second tick undoes the first', () => {
      const forYou = openedChecks().find(({ id }) => id === 'forYou')!;

      forYou.toggle();
      expect(forYou.checked()).toBe(false);
      forYou.toggle();

      expect(setInclusion.mock.calls.map(([, surface, included]) => [surface, included])).toEqual([
        ['forYou', false],
        ['forYou', true],
      ]);
      expect(forYou.checked()).toBe(true);
    });

    it('keeps the sheet open while it switches', () => {
      const closed = new Subject<string | undefined>();
      const choices = jest.fn();
      closed.subscribe({ next: choices, complete: choices });
      sheetOpen.mockReturnValue(closed);
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('sub-5');
      rows.openFeedSheet(feed);

      checksOf(sheetOpen.mock.calls[0][0])
        .find(({ id }) => id === 'forYou')!
        .toggle();

      expect(choices).not.toHaveBeenCalled();
      expect(rows.menuFor()).toBe('sub-5');
    });
  });

  describe('social accounts', () => {
    const bob = {
      id: 9,
      network: 'bluesky' as const,
      handle: '@bob.bsky.social',
      status: 'connected' as const,
    };
    const reconnect = {
      id: 4,
      network: 'bluesky' as const,
      handle: '@old.bsky.social',
      status: 'needs_reconnect' as const,
    };
    const sectionOf = (networkAccounts: SocialSection['networkAccounts']): SocialSection => ({
      network: 'bluesky',
      accounts: [],
      networkAccounts,
      groups: { search: socialFeedGroup([], 0), profile: socialFeedGroup([], 0) },
      saved: null,
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
    });
    const sheetOpen = jest.fn(() => of('9'));
    const pickerOpen = jest.fn(() => of(undefined));
    const includedNetworks = signal<ReadonlySet<SocialNetworkName>>(new Set());
    const toggleNetwork = jest.fn();
    const excludedNetworks = signal<ReadonlySet<SocialNetworkName>>(new Set());
    const forYouToggle = jest.fn();
    const allItemsExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
    const allItemsToggle = jest.fn();
    const trendingExcluded = signal<ReadonlySet<SocialNetworkName>>(new Set());
    const trendingToggle = jest.fn();

    beforeEach(() => {
      forYouToggle.mockClear();
      allItemsToggle.mockClear();
      trendingToggle.mockClear();
      trendingExcluded.set(new Set());
      allItemsExcluded.set(new Set());
      excludedNetworks.set(new Set());
      sheetOpen.mockClear();
      pickerOpen.mockClear();
      toggleNetwork.mockClear();
      includedNetworks.set(new Set());
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: {} },
          { provide: TagActions, useValue: {} },
          { provide: SocialFeedsPicker, useValue: { open: pickerOpen } },
          {
            provide: SavedSearchesStore,
            useValue: {
              excludedNetworks: computed(
                () =>
                  new Set(SOCIAL_NETWORKS.filter((network) => !includedNetworks().has(network))),
              ),
              toggleNetwork,
            },
          },
          {
            provide: NETWORK_EXCLUSION_STORES,
            useValue: {
              allItems: { excludedNetworks: allItemsExcluded, toggleNetwork: allItemsToggle },
              forYou: { excludedNetworks, toggleNetwork: forYouToggle },
              trending: { excludedNetworks: trendingExcluded, toggleNetwork: trendingToggle },
            },
          },
          { provide: SubscriptionsStore, useValue: { subscriptions: signal([]) } },
          { provide: TagsStore, useValue: tagsStoreStub(signal<TagDto[]>([])) },
        ],
      });
    });

    it('offers a lone connected account as plain Manage feeds', () => {
      expect(TestBed.inject(SidebarRowActions).socialAccountActions(sectionOf([bob]))).toEqual([
        { id: '9', label: 'Manage feeds…', disabled: false },
      ]);
    });

    it('names each of several accounts and disables one that needs reconnecting', () => {
      expect(
        TestBed.inject(SidebarRowActions).socialAccountActions(sectionOf([bob, reconnect])),
      ).toEqual([
        { id: '9', label: 'Manage feeds of @bob.bsky.social', disabled: false },
        { id: '4', label: 'Reconnect @old.bsky.social in settings', disabled: true },
      ]);
    });

    const checksOf = (rows: SidebarRowActions) => rows.networkSurfaceChecks(sectionOf([]));

    it('offers each account on the network sheet, then its four switches, and opens the picked one', () => {
      TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([bob, reconnect]));

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'Bluesky',
        actions: [
          { id: '9', label: 'Manage feeds of @bob.bsky.social', disabled: false },
          { id: '4', label: 'Reconnect @old.bsky.social in settings', disabled: true },
          ACTION_SHEET_DIVIDER,
          expect.objectContaining({ id: 'allItems', label: 'Show in All items' }),
          expect.objectContaining({ id: 'forYou', label: 'Show in For You' }),
          expect.objectContaining({ id: 'savedSearches', label: 'Show in saved searches' }),
          expect.objectContaining({ id: 'trending', label: 'Show in Trending' }),
        ],
      });
      expect(pickerOpen).toHaveBeenCalledWith(bob);
    });

    it('opens the network sheet of a network without accounts on its switches alone', () => {
      TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([]));

      expect(sheetOpen).toHaveBeenCalledWith(
        expect.objectContaining({
          actions: [
            expect.objectContaining({ id: 'allItems' }),
            expect.objectContaining({ id: 'forYou' }),
            expect.objectContaining({ id: 'savedSearches' }),
            expect.objectContaining({ id: 'trending' }),
          ],
        }),
      );
    });

    it('gives a network four checks, All items, For You, saved searches and Trending, that follow the stores', () => {
      const checks = checksOf(TestBed.inject(SidebarRowActions));

      expect(checks.map((check) => check.checked())).toEqual([true, true, false, true]);

      allItemsExcluded.set(new Set(['bluesky']));
      excludedNetworks.set(new Set(['bluesky']));
      includedNetworks.set(new Set(['bluesky']));
      trendingExcluded.set(new Set(['bluesky']));

      expect(checks.map((check) => check.checked())).toEqual([false, false, true, false]);
    });

    it('switches the network in each surface from its check and keeps the menu open', () => {
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('social-bluesky');
      const [allItems, forYou, savedSearches, trending] = checksOf(rows);

      allItems.toggle();
      forYou.toggle();
      savedSearches.toggle();
      trending.toggle();

      expect(trendingToggle).toHaveBeenCalledWith('bluesky');
      expect(allItemsToggle).toHaveBeenCalledWith('bluesky');
      expect(forYouToggle).toHaveBeenCalledWith('bluesky');
      expect(toggleNetwork).toHaveBeenCalledWith('bluesky');
      expect(rows.menuFor()).toBe('social-bluesky');
    });

    it('names every surface that leaves the network out, in title order', () => {
      allItemsExcluded.set(new Set(['bluesky']));
      excludedNetworks.set(new Set(['bluesky']));
      trendingExcluded.set(new Set(['bluesky']));

      expect(TestBed.inject(SidebarRowActions).networkExcludedSurfaces('bluesky')).toEqual([
        'allItems',
        'forYou',
        'savedSearches',
        'trending',
      ]);
    });

    it('closes the open menu when it opens the picker', () => {
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('social-bluesky');

      rows.manageSocialFeeds(bob);

      expect(rows.menuFor()).toBeNull();
      expect(pickerOpen).toHaveBeenCalledWith(bob);
    });
  });

  describe('the tag sheet', () => {
    const tag = (id: number, position: number, parentId: number | null = null): TagDto => ({
      id,
      name: `t${id}`,
      color: null,
      icon: null,
      position,
      parentId,
      ...INCLUDED_EVERYWHERE,
    });
    const sheetOpen = jest.fn();
    const reorderTags = jest.fn();
    const editTag = jest.fn();
    const deleteTag = jest.fn();
    const createTag = jest.fn();
    const setTagInclusion = jest.fn();
    const tags = signal<TagDto[]>([]);

    beforeEach(() => {
      [sheetOpen, reorderTags, editTag, deleteTag, createTag, setTagInclusion].forEach((spy) =>
        spy.mockReset(),
      );
      sheetOpen.mockReturnValue(of(undefined));
      tags.set([tag(1, 0), tag(2, 1), tag(3, 2), tag(11, 0, 1), tag(12, 1, 1)]);
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: {} },
          {
            provide: TagActions,
            useValue: { reorderTags, editTag, deleteTag, createTag, setTagInclusion },
          },
          { provide: NetworkSurfaceSwitches, useValue: {} },
          { provide: SubscriptionsStore, useValue: { subscriptions: signal([]) } },
          { provide: TagsStore, useValue: tagsStoreStub(tags) },
        ],
      });
    });

    const actionsFor = (target: TagDto) => {
      TestBed.inject(SidebarRowActions).openTagSheet(target);
      return sheetOpen.mock.calls[0][0].actions as { id?: string; disabled?: boolean }[];
    };

    it('offers edit, create here, the moves, the four switches and, after a divider, delete', () => {
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 't2',
        actions: [
          { id: 'edit', label: 'Edit tag', symbol: 'edit' },
          { id: 'createHere', label: 'Create tag here', symbol: 'add' },
          { id: 'moveUp', label: 'Move up', symbol: 'arrow_upward', disabled: false },
          { id: 'moveDown', label: 'Move down', symbol: 'arrow_downward', disabled: false },
          ACTION_SHEET_DIVIDER,
          expect.objectContaining({ id: 'allItems', label: 'Show in All items' }),
          expect.objectContaining({ id: 'forYou', label: 'Show in For You' }),
          expect.objectContaining({ id: 'savedSearches', label: 'Show in saved searches' }),
          expect.objectContaining({ id: 'trending', label: 'Show in Trending' }),
          ACTION_SHEET_DIVIDER,
          { id: 'delete', label: 'Delete tag', symbol: 'delete', danger: true },
        ],
      });
    });

    it('gives a tag four checks that follow the live tag in TagsStore', () => {
      const checks = TestBed.inject(SidebarRowActions).tagSurfaceChecks(tag(2, 1));

      expect(checks.map((check) => check.checked())).toEqual([true, true, true, true]);

      tags.update((current) =>
        current.map((listed) =>
          listed.id === 2
            ? { ...listed, includeInForYou: false, includeInTrending: false }
            : listed,
        ),
      );

      expect(checks.map((check) => check.checked())).toEqual([true, false, true, false]);
    });

    it('switches the tag from its check against the live tag, keeping the menu open', () => {
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('tag-2');
      const stale = tag(2, 1);
      const forYou = rows.tagSurfaceChecks(stale)[1];
      tags.update((current) =>
        current.map((listed) => (listed.id === 2 ? { ...listed, includeInForYou: false } : listed)),
      );

      forYou.toggle();

      expect(setTagInclusion).toHaveBeenCalledWith(stale, 'forYou', true);
      expect(rows.menuFor()).toBe('tag-2');
    });

    it('disables Move up on the first sibling', () => {
      const actions = actionsFor(tag(1, 0));
      expect(actions.find((action) => action.id === 'moveUp')?.disabled).toBe(true);
      expect(actions.find((action) => action.id === 'moveDown')?.disabled).toBe(false);
    });

    it('disables Move down on the last child of its parent, not of the roots', () => {
      const actions = actionsFor(tag(12, 1, 1));
      expect(actions.find((action) => action.id === 'moveUp')?.disabled).toBe(false);
      expect(actions.find((action) => action.id === 'moveDown')?.disabled).toBe(true);
    });

    it('moves a root up among the roots', () => {
      sheetOpen.mockReturnValue(of('moveUp'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      expect(reorderTags).toHaveBeenCalledWith([2, 1, 3]);
    });

    it('moves a child down within its parent only', () => {
      sheetOpen.mockReturnValue(of('moveDown'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(11, 0, 1));
      expect(reorderTags).toHaveBeenCalledWith([12, 11]);
    });

    it('moves against the order current when the choice lands, not when the sheet opened', () => {
      const choice = new Subject<string>();
      sheetOpen.mockReturnValue(choice);
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      tags.set([tag(4, 0), tag(1, 1), tag(2, 2), tag(3, 3)]);
      choice.next('moveUp');

      expect(reorderTags).toHaveBeenCalledWith([4, 2, 1, 3]);
    });

    it('drops a move the current order no longer allows', () => {
      const choice = new Subject<string>();
      sheetOpen.mockReturnValue(choice);
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      tags.set([tag(2, 0), tag(1, 1), tag(3, 2)]);
      choice.next('moveUp');

      expect(reorderTags).not.toHaveBeenCalled();
    });

    it('offers Create tag here on a child tag, which still has a level below it', () => {
      const actions = actionsFor(tag(11, 0, 1));
      expect(actions.find((action) => action.id === 'createHere')).toBeDefined();
    });

    it('offers no Create tag here on a grandchild tag', () => {
      tags.update((current) => [...current, tag(111, 0, 11)]);
      const actions = actionsFor(tag(111, 0, 11));
      expect(actions.find((action) => action.id === 'createHere')).toBeUndefined();
    });

    it('creates a tag under the root it was opened on', () => {
      sheetOpen.mockReturnValue(of('createHere'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      expect(createTag).toHaveBeenCalledWith(2);
      expect(reorderTags).not.toHaveBeenCalled();
    });

    it('the Tags head sheet offers New tag and creates at the top level', () => {
      sheetOpen.mockReturnValue(of('new'));
      TestBed.inject(SidebarRowActions).openTagsHeadSheet();
      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'Tags',
        actions: [{ id: 'new', label: 'New tag', symbol: 'add' }],
      });
      expect(createTag).toHaveBeenCalledWith();
    });

    it('still routes edit and delete', () => {
      sheetOpen.mockReturnValueOnce(of('edit'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));
      sheetOpen.mockReturnValueOnce(of('delete'));
      TestBed.inject(SidebarRowActions).openTagSheet(tag(3, 2));

      expect(editTag).toHaveBeenCalledWith(tag(2, 1));
      expect(deleteTag).toHaveBeenCalledWith(tag(3, 2));
      expect(reorderTags).not.toHaveBeenCalled();
    });
  });
});
