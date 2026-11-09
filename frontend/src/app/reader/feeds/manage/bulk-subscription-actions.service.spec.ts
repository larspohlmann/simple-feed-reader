import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ReplaySubject } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { BulkSubscriptionActions } from './bulk-subscription-actions.service';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SubscriptionDto, TagDto } from '../../models';
import { makeSubscription } from '../../testing/subscription.factory';
import { ToastService } from '../../../shared/toast/toast.service';

const BASE = 'https://api.test';

const SUBSCRIPTION: SubscriptionDto = makeSubscription({
  id: 5,
  feedId: 50,
  title: 'Heise',
  feedUrl: 'u',
  createdAt: 'x',
});
const TAG: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0, parentId: null };

describe('BulkSubscriptionActions', () => {
  const subscriptions = (count: number): SubscriptionDto[] =>
    Array.from({ length: count }, (_, index) => ({
      ...SUBSCRIPTION,
      id: index + 1,
      title: `Feed ${index + 1}`,
    }));

  let http: HttpTestingController;
  afterEach(() => http.verify());

  // The dialog mock returns a replayable `closed`, so a test can answer the
  // confirmation before the code under test subscribes.
  function setup(options: { confirmAnswer?: boolean } = {}) {
    const closed = new ReplaySubject<unknown>(1);
    const dialogOpen = jest.fn<
      { closed: ReplaySubject<unknown> },
      [unknown, { data: { title: string; message: string; requireText?: string } }]
    >(() => ({ closed }));
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: Dialog, useValue: { open: dialogOpen } },
        // ConfirmService and ToastService share the `Dialog` token; the stub above's
        // bare { closed } object lacks the `overlayRef` ToastService.show() reads, so
        // a real call inside bulkPatch()/bulkUnsubscribe() would throw against it.
        { provide: ToastService, useValue: { show: jest.fn() } },
      ],
    });
    const actions = TestBed.inject(BulkSubscriptionActions);
    http = TestBed.inject(HttpTestingController);
    const subLoad = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    const savedSearchesLoad = jest
      .spyOn(TestBed.inject(SavedSearchesStore), 'load')
      .mockImplementation(() => undefined);

    if (options.confirmAnswer !== undefined) closed.next(options.confirmAnswer);

    return { actions, http, dialogOpen, subLoad, savedSearchesLoad };
  }

  it('posts one bulk patch when a tag is added to several feeds', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkAddTag([1, 2, 3], TAG).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [1, 2, 3], addTagIds: [TAG.id] });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('posts removeTagIds when a tag is removed', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkRemoveTag([4], TAG).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.body).toEqual({
      subscriptionIds: [4],
      removeTagIds: [TAG.id],
    });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('sends only the flag that was named', () => {
    const { actions, http } = setup();
    let emitted = false;

    actions.bulkSetFlags([7], { includeInAllItems: false }).subscribe(() => (emitted = true));

    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(testRequest.request.body).toEqual({
      subscriptionIds: [7],
      includeInAllItems: false,
    });
    testRequest.flush({ subscriptions: [] });

    expect(emitted).toBe(true);
  });

  it('reloads the subscriptions store after a successful bulk patch', () => {
    const { actions, http, subLoad, savedSearchesLoad } = setup();

    actions.bulkAddTag([1], TAG).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });

    expect(subLoad).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('reloads saved searches after a bulk patch of the saved-search flag only', () => {
    const { actions, http, savedSearchesLoad } = setup();

    actions.bulkSetFlags([1], { includeInForYou: false }).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });
    expect(savedSearchesLoad).not.toHaveBeenCalled();

    actions.bulkSetFlags([1], { includeInSavedSearches: false }).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });
    expect(savedSearchesLoad).toHaveBeenCalledTimes(1);
  });

  it('asks the user to type the count from five feeds up', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(5)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBe('5');
  });

  describe('where saved posts go', () => {
    const bluesky = makeSubscription({
      id: 21,
      title: 'Bob',
      social: { network: 'bluesky', accountId: null, kind: 'profile' },
    });
    const confirmMessage = (leaving: SubscriptionDto[]): string => {
      const { actions, dialogOpen } = setup();
      actions.bulkUnsubscribe(leaving).subscribe();
      return dialogOpen.mock.calls[0][1].data.message;
    };

    it('names the one Saved feed that all leaving feeds share', () => {
      expect(confirmMessage(subscriptions(2))).toContain('move to Saved from feeds');
    });

    it('says "their Saved feeds" when the destinations differ', () => {
      expect(confirmMessage([subscriptions(1)[0], bluesky])).toContain('move to their Saved feeds');
    });

    it('warns about a saved feed leaving with the others', () => {
      const savedFeed = makeSubscription({
        id: 22,
        title: 'Saved from feeds',
        sourceFormat: 'saved',
      });

      expect(confirmMessage([bluesky, savedFeed])).toContain(
        'The saved posts in Saved from feeds are deleted for good.',
      );
    });

    it('says the posts move to a new Saved feed when their destination leaves too', () => {
      const savedFeed = makeSubscription({
        id: 22,
        title: 'Saved from feeds',
        sourceFormat: 'saved',
      });

      expect(confirmMessage([subscriptions(1)[0], savedFeed])).toContain(
        'move to a new Saved from feeds.',
      );
    });
  });

  it('does not ask for typed text at four feeds', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(4)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBeUndefined();
  });

  it('singularises the confirmation title at a selection of one', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(1)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.title).toBe('Unsubscribe from 1 feed?');
  });

  it('names at most five titles and counts the rest', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subscriptions(7)).subscribe();

    const message: string = dialogOpen.mock.calls[0][1].data.message;
    expect(message).toContain('Feed 1');
    expect(message).toContain('Feed 5');
    expect(message).not.toContain('Feed 6');
  });

  it('writes nothing when the confirmation is dismissed', () => {
    const { actions, http } = setup({ confirmAnswer: false });
    let outcome: boolean | undefined;

    actions.bulkUnsubscribe(subscriptions(2)).subscribe((ok) => (outcome = ok));

    expect(outcome).toBe(false);
    http.expectNone(`${BASE}/api/subscriptions/bulk-unsubscribe`);
  });

  it('unsubscribes and reloads after a confirmed bulk unsubscribe', () => {
    const { actions, http, subLoad } = setup({ confirmAnswer: true });

    actions.bulkUnsubscribe(subscriptions(2)).subscribe();
    const testRequest = http.expectOne(`${BASE}/api/subscriptions/bulk-unsubscribe`);
    expect(testRequest.request.body).toEqual({ subscriptionIds: [1, 2] });
    testRequest.flush({ removed: 2 });

    expect(subLoad).toHaveBeenCalled();
  });
});
