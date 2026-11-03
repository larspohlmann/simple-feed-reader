import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SharingService } from '../../../core/preferences/sharing.service';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { EntriesStore } from '../../state/entries.store';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { NetworkShares } from './network-shares.service';

describe('NetworkShares', () => {
  const recordsShares = signal(true);
  let listed: EntryDto | null;
  let entries: { entry: jest.Mock; markSharedLocally: jest.Mock };
  let subscriptions: { bumpShared: jest.Mock };

  function shares(): NetworkShares {
    TestBed.configureTestingModule({
      providers: [
        { provide: EntriesStore, useValue: entries },
        { provide: SubscriptionsStore, useValue: subscriptions },
        { provide: SharingService, useValue: { recordsShares } },
      ],
    });
    return TestBed.inject(NetworkShares);
  }

  beforeEach(() => {
    recordsShares.set(true);
    listed = anEntry({ id: 4, isShared: false });
    entries = { entry: jest.fn(() => listed), markSharedLocally: jest.fn() };
    subscriptions = { bumpShared: jest.fn() };
  });

  it('marks the entry shared and counts it once', () => {
    shares().reflect(4);

    expect(entries.entry).toHaveBeenCalledWith(4);
    expect(entries.markSharedLocally).toHaveBeenCalledWith(4);
    expect(subscriptions.bumpShared).toHaveBeenCalledTimes(1);
    expect(subscriptions.bumpShared).toHaveBeenCalledWith(1);
  });

  it('does not count an entry already shared again', () => {
    listed = anEntry({ id: 4, isShared: true });

    shares().reflect(4);

    expect(entries.markSharedLocally).toHaveBeenCalledWith(4);
    expect(subscriptions.bumpShared).not.toHaveBeenCalled();
  });

  it('changes nothing while shares are not recorded', () => {
    recordsShares.set(false);

    shares().reflect(4);

    expect(entries.markSharedLocally).not.toHaveBeenCalled();
    expect(subscriptions.bumpShared).not.toHaveBeenCalled();
  });
});
