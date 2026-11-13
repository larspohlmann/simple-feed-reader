import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../../core/api';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { SubscriptionReload } from './subscription-reload.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { EntriesStore } from '../../state/entries.store';

describe('SubscriptionReload', () => {
  let reload: SubscriptionReload;
  let reloadSubscriptions: jest.SpyInstance;
  let reloadSavedSearches: jest.SpyInstance;
  let reloadTrending: jest.SpyInstance;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    reload = TestBed.inject(SubscriptionReload);
    reloadSubscriptions = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    reloadSavedSearches = jest
      .spyOn(TestBed.inject(SavedSearchesStore), 'load')
      .mockImplementation(() => undefined);
    reloadTrending = jest
      .spyOn(TestBed.inject(EntriesStore), 'reloadTrending')
      .mockImplementation(() => undefined);
  });

  it('reloads only the subscriptions when the write left the saved-search flag alone', () => {
    reload.afterWrite({});

    expect(reloadSubscriptions).toHaveBeenCalledTimes(1);
    expect(reloadSavedSearches).not.toHaveBeenCalled();
    expect(reloadTrending).not.toHaveBeenCalled();
  });

  it.each([true, false])(
    'also reloads saved searches when the write set the flag to %s',
    (includeInSavedSearches) => {
      reload.afterWrite({ includeInSavedSearches });

      expect(reloadSubscriptions).toHaveBeenCalledTimes(1);
      expect(reloadSavedSearches).toHaveBeenCalledTimes(1);
    },
  );

  it.each([true, false])(
    'also reloads the Trending list when the write set its flag to %s',
    (includeInTrending) => {
      reload.afterWrite({ includeInTrending });

      expect(reloadSubscriptions).toHaveBeenCalledTimes(1);
      expect(reloadTrending).toHaveBeenCalledTimes(1);
      expect(reloadSavedSearches).not.toHaveBeenCalled();
    },
  );
});
