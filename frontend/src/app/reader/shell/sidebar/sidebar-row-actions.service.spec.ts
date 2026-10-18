import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { heldUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { SocialFeedsPicker } from '../../feeds/social-picker/social-feeds-picker.service';
import { socialProfiles, SocialSection } from '../../state/social-sections';
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
      profiles: socialProfiles([]),
      saved: null,
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
    });
    const sheetOpen = jest.fn(() => of('9'));
    const pickerOpen = jest.fn(() => of(undefined));

    beforeEach(() => {
      sheetOpen.mockClear();
      pickerOpen.mockClear();
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          SidebarRowActions,
          { provide: ActionSheet, useValue: { open: sheetOpen } },
          { provide: ManageActions, useValue: {} },
          { provide: SocialFeedsPicker, useValue: { open: pickerOpen } },
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
        ],
      });
      expect(pickerOpen).toHaveBeenCalledWith(bob);
    });

    it('closes the open menu when it opens the picker', () => {
      const rows = TestBed.inject(SidebarRowActions);
      rows.menuFor.set('social-bluesky');

      rows.manageSocialFeeds(bob);

      expect(rows.menuFor()).toBeNull();
      expect(pickerOpen).toHaveBeenCalledWith(bob);
    });
  });
});
