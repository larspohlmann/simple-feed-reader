import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ActionSheet } from '../../../shared/action-sheet/action-sheet.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { makeSubscription } from '../../testing/subscription.factory';
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
    const home = makeSubscription({
      title: 'Home',
      social: { network: 'bluesky', accountId: 1, kind: 'home' },
    });

    TestBed.inject(SidebarRowActions).openFeedSheet(home);

    expect(open).toHaveBeenCalledWith(expect.objectContaining({ title: 'Home@bsky' }));
  });
});
