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

  it('offers each account on the network sheet and opens the picked one', () => {
    const open = jest.fn(() => of('9'));
    const pickerOpen = jest.fn(() => of(undefined));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        SidebarRowActions,
        { provide: ActionSheet, useValue: { open } },
        { provide: ManageActions, useValue: {} },
        { provide: SocialFeedsPicker, useValue: { open: pickerOpen } },
      ],
    });
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
    const section: SocialSection = {
      network: 'bluesky',
      accounts: [],
      networkAccounts: [bob, reconnect],
      profiles: socialProfiles([]),
      saved: null,
      unreadCount: 0,
      entryCount: 0,
      showsHandles: false,
    };

    TestBed.inject(SidebarRowActions).openSocialSheet(section);

    expect(open).toHaveBeenCalledWith({
      title: 'Bluesky',
      actions: [
        { id: '9', label: 'Manage feeds of @bob.bsky.social', disabled: false },
        { id: '4', label: 'Reconnect @old.bsky.social in settings', disabled: true },
      ],
    });
    expect(pickerOpen).toHaveBeenCalledWith(bob);
  });
});
