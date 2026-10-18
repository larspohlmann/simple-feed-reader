import { TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { SocialFeedsPicker } from './social-feeds-picker.service';
import { SocialImportDialogComponent } from './social-import-dialog.component';

describe('SocialFeedsPicker', () => {
  it('opens the picker for the account and hands back how it closed', () => {
    const result = {
      imported: 1,
      alreadySubscribed: 0,
      invalid: 0,
      skippedOverLimit: 0,
      removed: 0,
    };
    const open = jest.fn(() => ({ closed: of(result) }));
    TestBed.configureTestingModule({ providers: [{ provide: Dialog, useValue: { open } }] });
    const closed = jest.fn();

    TestBed.inject(SocialFeedsPicker)
      .open({ id: 4, network: 'bluesky', handle: '@alice.bsky.social' })
      .subscribe(closed);

    expect(open).toHaveBeenCalledWith(SocialImportDialogComponent, {
      data: { accountId: 4, network: 'bluesky', handle: '@alice.bsky.social' },
      panelClass: 'app-dialog',
    });
    expect(closed).toHaveBeenCalledWith(result);
  });
});
