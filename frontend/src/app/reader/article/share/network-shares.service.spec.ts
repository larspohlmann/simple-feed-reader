import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { EntryRowCache } from '../../state/entry-row-cache';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { NetworkShares } from './network-shares.service';

describe('NetworkShares', () => {
  const recordsShares = signal(true);
  let listed: EntryDto | null;
  let rows: { entry: jest.Mock; markSharedLocally: jest.Mock };
  let subscriptions: { bumpCount: jest.Mock };

  function shares(): NetworkShares {
    TestBed.configureTestingModule({
      providers: [
        { provide: EntryRowCache, useValue: rows },
        { provide: SubscriptionsStore, useValue: subscriptions },
        { provide: SharingService, useValue: { recordsShares } },
      ],
    });
    return TestBed.inject(NetworkShares);
  }

  beforeEach(() => {
    recordsShares.set(true);
    listed = anEntry({ id: 4, isShared: false });
    rows = { entry: jest.fn(() => listed), markSharedLocally: jest.fn() };
    subscriptions = { bumpCount: jest.fn() };
  });

  it('marks the entry shared and counts it once', () => {
    shares().reflect(4);

    expect(rows.entry).toHaveBeenCalledWith(4);
    expect(rows.markSharedLocally).toHaveBeenCalledWith(4);
    expect(subscriptions.bumpCount).toHaveBeenCalledTimes(1);
    expect(subscriptions.bumpCount).toHaveBeenCalledWith('shared', 1);
  });

  it('does not count an entry already shared again', () => {
    listed = anEntry({ id: 4, isShared: true });

    shares().reflect(4);

    expect(rows.markSharedLocally).toHaveBeenCalledWith(4);
    expect(subscriptions.bumpCount).not.toHaveBeenCalled();
  });

  it('changes nothing while shares are not recorded', () => {
    recordsShares.set(false);

    shares().reflect(4);

    expect(rows.markSharedLocally).not.toHaveBeenCalled();
    expect(subscriptions.bumpCount).not.toHaveBeenCalled();
  });
});
