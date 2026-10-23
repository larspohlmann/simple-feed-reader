import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { signal } from '@angular/core';
import { SocialNetworkName } from '../../../core/social-network';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { heldUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { SocialFeedsPicker } from '../../feeds/social-picker/social-feeds-picker.service';
import { socialProfiles, SocialSection } from '../../state/social-sections';
import { TagsStore } from '../../state/tags.store';
import { TagDto } from '../../models';
import { ACTION_SHEET_DIVIDER } from '../../../shared/action-sheet/action-sheet.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

describe('SidebarRowActions', () => {
  it("titles an unrenamed Home's feed sheet with the Home's label", () => {
    const open = jest.fn(() => of(undefined));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        SidebarRowActions,
        { provide: ActionSheet, useValue: { open } },
        { provide: ManageActions, useValue: {} },
        { provide: SavedSearchesStore, useValue: {} },
        { provide: TagsStore, useValue: { tags: signal<TagDto[]>([]) } },
      ],
    });
    TestBed.inject(SidebarRowActions).openFeedSheet(heldUnrenamedHome(1));

    expect(open).toHaveBeenCalledWith(expect.objectContaining({ title: 'Home@bsky' }));
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
      profiles: socialProfiles([], 0),
      saved: null,
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
    });
    const sheetOpen = jest.fn(() => of('9'));
    const pickerOpen = jest.fn(() => of(undefined));
    const includedNetworks = signal<ReadonlySet<SocialNetworkName>>(new Set());
    const toggleNetwork = jest.fn();

    beforeEach(() => {
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
          { provide: SocialFeedsPicker, useValue: { open: pickerOpen } },
          {
            provide: SavedSearchesStore,
            useValue: { includedNetworks, toggleNetwork },
          },
          { provide: TagsStore, useValue: { tags: signal<TagDto[]>([]) } },
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

    it('offers each account on the network sheet and opens the picked one', () => {
      TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([bob, reconnect]));

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 'Bluesky',
        actions: [
          { id: '9', label: 'Manage feeds of @bob.bsky.social', disabled: false },
          { id: '4', label: 'Reconnect @old.bsky.social in settings', disabled: true },
          { id: 'savedSearches', label: 'Include in saved searches' },
        ],
      });
      expect(pickerOpen).toHaveBeenCalledWith(bob);
    });

    it('switches the network in saved searches from the sheet', () => {
      sheetOpen.mockReturnValueOnce(of('savedSearches'));

      TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([]));

      expect(toggleNetwork).toHaveBeenCalledWith('bluesky');
      expect(pickerOpen).not.toHaveBeenCalled();
    });

    it('switches the network from its menu item and closes the menu', () => {
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('social-bluesky');

      rows.toggleNetworkInSavedSearches(sectionOf([]));

      expect(toggleNetwork).toHaveBeenCalledWith('bluesky');
      expect(rows.menuFor()).toBeNull();
    });

    it('labels the network item by whether the network is included', () => {
      const rows = TestBed.inject(SidebarRowActions);
      expect(rows.networkSavedSearchesAction(sectionOf([]))).toEqual({
        id: 'savedSearches',
        label: 'Include in saved searches',
      });

      includedNetworks.set(new Set(['bluesky']));

      expect(rows.networkSavedSearchesAction(sectionOf([])).label).toBe(
        'Exclude from saved searches',
      );
    });

    it('offers to exclude an included network and excludes it on that choice', () => {
      includedNetworks.set(new Set(['bluesky']));
      sheetOpen.mockReturnValueOnce(of('savedSearches'));

      TestBed.inject(SidebarRowActions).openSocialSheet(sectionOf([]));

      expect(sheetOpen).toHaveBeenCalledWith(
        expect.objectContaining({
          actions: [{ id: 'savedSearches', label: 'Exclude from saved searches' }],
        }),
      );
      expect(toggleNetwork).toHaveBeenCalledWith('bluesky');
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
    });
    const sheetOpen = jest.fn();
    const reorderTags = jest.fn();
    const editTag = jest.fn();
    const deleteTag = jest.fn();
    const tags = signal<TagDto[]>([]);

    beforeEach(() => {
      [sheetOpen, reorderTags, editTag, deleteTag].forEach((spy) => spy.mockReset());
      sheetOpen.mockReturnValue(of(undefined));
      tags.set([tag(1, 0), tag(2, 1), tag(3, 2), tag(11, 0, 1), tag(12, 1, 1)]);
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: { reorderTags, editTag, deleteTag } },
          { provide: SavedSearchesStore, useValue: {} },
          { provide: TagsStore, useValue: { tags } },
        ],
      });
    });

    const actionsFor = (target: TagDto) => {
      TestBed.inject(SidebarRowActions).openTagSheet(target);
      return sheetOpen.mock.calls[0][0].actions as { id?: string; disabled?: boolean }[];
    };

    it('offers edit, move up, move down and, after a divider, delete', () => {
      TestBed.inject(SidebarRowActions).openTagSheet(tag(2, 1));

      expect(sheetOpen).toHaveBeenCalledWith({
        title: 't2',
        actions: [
          { id: 'edit', label: 'Edit tag', symbol: 'edit' },
          { id: 'moveUp', label: 'Move up', symbol: 'arrow_upward', disabled: false },
          { id: 'moveDown', label: 'Move down', symbol: 'arrow_downward', disabled: false },
          ACTION_SHEET_DIVIDER,
          { id: 'delete', label: 'Delete tag', symbol: 'delete', danger: true },
        ],
      });
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
