import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ReplaySubject, of } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { ManageActions } from './manage-actions.service';
import { UNSUBSCRIBE_REQUESTED } from './edit-subscription-dialog.component';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { TagsStore } from '../../state/tags.store';
import { TagDrop } from '../../tag-drop';
import { RefreshReport, SubscriptionDto, TagDto } from '../../models';
import { makeSubscription } from '../../testing/subscription.factory';
import { heldUnrenamedHome } from '../../../../testing/unrenamed-home-testing';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../../shared/toast/toast.service';
import { TranslocoService } from '@jsverse/transloco';

const BASE = 'https://api.test';

const sub: SubscriptionDto = makeSubscription({
  id: 5,
  feedId: 50,
  title: 'Heise',
  feedUrl: 'u',
  createdAt: 'x',
});
const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0, parentId: null };
const TAG = tag;
const SUBSCRIPTION = sub;

describe('ManageActions', () => {
  let service: ManageActions;
  let ctrl: HttpTestingController;
  let closed: unknown;
  let savedSearchesLoad: jest.SpyInstance;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Dialog, useValue: { open } },
      ],
    });
    service = TestBed.inject(ManageActions);
    ctrl = TestBed.inject(HttpTestingController);
    savedSearchesLoad = jest
      .spyOn(TestBed.inject(SavedSearchesStore), 'load')
      .mockImplementation(() => undefined);
    open.mockClear();
  });
  afterEach(() => ctrl.verify());

  it('reloads subscriptions after a successful edit', () => {
    closed = sub; // dialog closed with an updated subscription
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('reloads saved searches after an edit that switched the saved-search flag', () => {
    closed = { ...sub, includeInSavedSearches: !sub.includeInSavedSearches };
    jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(savedSearchesLoad).toHaveBeenCalled();
  });

  it('opens the unsubscribe confirmation when the edit dialog asks for it', () => {
    open.mockReturnValueOnce({ closed: of(UNSUBSCRIBE_REQUESTED) });
    closed = true; // the confirmation that follows returns true
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.editSubscription(sub);
    expect(open).toHaveBeenCalledTimes(2);
    ctrl.expectOne({ method: 'DELETE', url: 'https://api.test/api/subscriptions/5' }).flush(null);
    expect(spy).toHaveBeenCalledTimes(1);
  });

  it('unsubscribe: on confirm, DELETEs then reloads', () => {
    closed = true; // confirm dialog returned true
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.unsubscribe(sub);
    ctrl.expectOne('https://api.test/api/subscriptions/5').flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it("unsubscribe: names an unrenamed Home by the Home's label", () => {
    closed = undefined;
    service.unsubscribe(heldUnrenamedHome(6));

    expect(JSON.stringify(open.mock.calls[0])).toContain('Home@bsky');
  });

  it('unsubscribe: says where favourites and kept posts go', () => {
    closed = undefined;
    service.unsubscribe(
      makeSubscription({
        id: 7,
        title: 'Bob',
        social: { network: 'bluesky', accountId: null, kind: 'profile' },
      }),
    );

    expect(JSON.stringify(open.mock.calls[0])).toContain(
      'Favourites and kept posts move to Saved from Bluesky.',
    );
  });

  it('unsubscribe: warns that a saved feed’s posts are deleted for good', () => {
    closed = undefined;
    service.unsubscribe(
      makeSubscription({ id: 8, title: 'Saved from feeds', sourceFormat: 'saved' }),
    );

    expect(JSON.stringify(open.mock.calls[0])).toContain('Its saved posts are deleted for good.');
  });

  it('unsubscribe: on cancel, does nothing', () => {
    closed = undefined;
    service.unsubscribe(sub);
    ctrl.expectNone('https://api.test/api/subscriptions/5');
  });

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    const subSpy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.deleteTag(tag);
    ctrl.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  it('moveFeedToTag: PATCHes /move-to-tag with from/to/position then reloads', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.moveFeedToTag(tagged, { fromTagId: 3, toTagId: 7, position: 1 });
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5/move-to-tag');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ fromTagId: 3, toTagId: 7, position: 1 });
    testRequest.flush({ subscription: tagged });
    expect(spy).toHaveBeenCalled();
  });

  it('moveFeedToTag: optimistically drops the source tag and adds the target', () => {
    const store = TestBed.inject(SubscriptionsStore);
    const tags = TestBed.inject(TagsStore);
    const news: TagDto = {
      id: 7,
      name: 'News',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    tags.tags.set([tag, news]);
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    store.storedSubscriptions.set([tagged]);
    jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.moveFeedToTag(tagged, { fromTagId: 3, toTagId: 7, position: 0 });
    expect(
      store
        .subscriptions()
        .find((x) => x.id === 5)!
        .tags.map((assignedTag) => assignedTag.id),
    ).toEqual([7]);
    ctrl
      .expectOne('https://api.test/api/subscriptions/5/move-to-tag')
      .flush({ subscription: tagged });
  });

  it('moveFeedToTag: does nothing when the source and target lists are the same', () => {
    service.moveFeedToTag(sub, { fromTagId: 3, toTagId: 3, position: 0 });
    ctrl.expectNone('https://api.test/api/subscriptions/5/move-to-tag');
  });

  it('setIncludeInAllItems: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.setIncludeInAllItems(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInAllItems).toBe(false);
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInAllItems: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInAllItems: false } });
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('setIncludeInForYou: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const spy = jest.spyOn(store, 'reloadQuietly').mockImplementation(() => undefined);
    service.setIncludeInForYou(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInForYou).toBe(false);
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInForYou: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInForYou: false } });
    expect(spy).toHaveBeenCalled();
    expect(savedSearchesLoad).not.toHaveBeenCalled();
  });

  it('setIncludeInSavedSearches: PATCHes the flag, then reloads subscriptions and saved searches', () => {
    const tagged: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.storedSubscriptions.set([tagged]);
    const subscriptionsLoad = jest
      .spyOn(store, 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.setIncludeInSavedSearches(tagged, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInSavedSearches).toBe(false);
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: tagged.customTitle,
      tagIds: [3],
      includeInSavedSearches: false,
    });
    testRequest.flush({ subscription: { ...tagged, includeInSavedSearches: false } });
    expect(subscriptionsLoad).toHaveBeenCalled();
    expect(savedSearchesLoad).toHaveBeenCalled();
  });

  it('reorderTags: PATCHes /api/tags/reorder then reloads tags', () => {
    const spy = jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    service.reorderTags([3, 1, 2]);
    const testRequest = ctrl.expectOne('https://api.test/api/tags/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ tagIds: [3, 1, 2] });
    testRequest.flush({ tags: [] });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTags: repositions only the listed siblings and keeps every other tag', () => {
    jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    const tags = TestBed.inject(TagsStore);
    const reading: TagDto = {
      id: 1,
      name: 'Reading',
      color: null,
      icon: null,
      position: 0,
      parentId: null,
    };
    const longReads: TagDto = {
      id: 2,
      name: 'Long reads',
      color: null,
      icon: null,
      position: 0,
      parentId: 1,
    };
    const tech: TagDto = {
      id: 4,
      name: 'Tech',
      color: null,
      icon: null,
      position: 1,
      parentId: null,
    };
    tags.tags.set([reading, longReads, tech]);

    service.reorderTags([4, 1]);

    expect(tags.tags().map((tag) => [tag.id, tag.position])).toEqual([
      [2, 0],
      [4, 0],
      [1, 1],
    ]);
    ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
  });

  describe('moveTag', () => {
    const tagA: TagDto = { id: 1, name: 'A', color: null, icon: null, position: 0, parentId: null };
    const tagB: TagDto = {
      id: 2,
      name: 'B',
      color: '#fff',
      icon: 'x',
      position: 1,
      parentId: null,
    };
    let tagsStore: TagsStore;
    let tagsLoad: jest.SpyInstance;
    let subscriptionsLoad: jest.SpyInstance;

    const dropUnder = (parentId: number | null, order: number[]): TagDrop => ({
      parentId,
      order,
      mark: { kind: 'before', tagId: order[0] },
    });

    beforeEach(() => {
      tagsStore = TestBed.inject(TagsStore);
      tagsStore.tags.set([tagA, tagB]);
      tagsLoad = jest.spyOn(tagsStore, 'load').mockImplementation(() => undefined);
      subscriptionsLoad = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
    });

    it('only reorders when the parent stays, showing the new positions at once', () => {
      service.moveTag(tagB, dropUnder(null, [2, 1]));

      expect(tagsStore.tags().map(({ id, position }) => [id, position])).toEqual([
        [2, 0],
        [1, 1],
      ]);
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2, 1] });
      reorder.flush({ tags: [] });
      ctrl.expectNone('https://api.test/api/tags/2');
    });

    it('re-parents, then writes the new sibling order, then reloads tags and feeds', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      const reparent = ctrl.expectOne('https://api.test/api/tags/2');
      expect(reparent.request.body).toEqual({ name: 'B', color: '#fff', icon: 'x', parentId: 1 });
      ctrl.expectNone('https://api.test/api/tags/reorder');
      reparent.flush({ tag: tagB });
      const reorder = ctrl.expectOne('https://api.test/api/tags/reorder');
      expect(reorder.request.body).toEqual({ tagIds: [2] });
      reorder.flush({ tags: [] });
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });

    it('shows the new parent at once', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      expect(tagsStore.tags().find((tag) => tag.id === 2)).toEqual(
        expect.objectContaining({ parentId: 1, position: 0 }),
      );
      ctrl.expectOne('https://api.test/api/tags/2').flush({ tag: tagB });
      ctrl.expectOne('https://api.test/api/tags/reorder').flush({ tags: [] });
    });

    it('skips the reorder and still reloads when the re-parent is refused', () => {
      service.moveTag(tagB, dropUnder(1, [2]));

      ctrl
        .expectOne('https://api.test/api/tags/2')
        .flush(null, { status: 422, statusText: 'Unprocessable' });
      ctrl.expectNone('https://api.test/api/tags/reorder');
      expect(tagsLoad).toHaveBeenCalled();
      expect(subscriptionsLoad).toHaveBeenCalled();
    });
  });

  it('reorderUntagged: PATCHes /api/subscriptions/reorder then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.reorderUntagged([9, 8, 7]);
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/reorder');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [9, 8, 7] });
    testRequest.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTagFeeds: PATCHes /api/tags/{id}/feed-order then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
      .mockImplementation(() => undefined);
    service.reorderTagFeeds(4, [2, 1]);
    const testRequest = ctrl.expectOne('https://api.test/api/tags/4/feed-order');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({ subscriptionIds: [2, 1] });
    testRequest.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    service.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });

  it.each([
    ['at the top level by default', undefined, null],
    ['under the given parent', 7, 7],
  ])('createTag: opens the dialog creating %s', (_case, parentId, expectedParentId) => {
    closed = undefined;
    service.createTag(parentId);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'create', parentId: expectedParentId },
      panelClass: 'app-dialog',
    });
  });

  it('editTag: opens the dialog editing the tag', () => {
    closed = undefined;
    service.editTag(tag);
    expect(open).toHaveBeenCalledWith(expect.anything(), {
      data: { kind: 'edit', tag },
      panelClass: 'app-dialog',
    });
  });

  describe('bulk actions', () => {
    const subscriptions = (count: number): SubscriptionDto[] =>
      Array.from({ length: count }, (_, index) => ({
        ...SUBSCRIPTION,
        id: index + 1,
        title: `Feed ${index + 1}`,
      }));

    // setup() gives each test its own TestBed, so the outer suite's `ctrl.verify()`
    // checks the wrong, detached controller here. This local `http` verifies the
    // controller setup() actually injected, catching e.g. a doubly-subscribed Observable.
    let http: HttpTestingController;
    afterEach(() => http.verify());

    // Own TestBed per call: the dialog mock here must return a replayable
    // `closed` (so a test can resolve it before the code under test
    // subscribes), which the outer suite's `of(closed)` cannot do.
    function setup(options: { confirmAnswer?: boolean } = {}) {
      TestBed.resetTestingModule();
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
          // ManageActions and ToastService share the `Dialog` token; the stub above's
          // bare { closed } object lacks the `overlayRef` ToastService.show() reads, so
          // a real call inside bulkPatch()/bulkUnsubscribe() would throw against it.
          { provide: ToastService, useValue: { show: jest.fn() } },
        ],
      });
      const actions = TestBed.inject(ManageActions);
      http = TestBed.inject(HttpTestingController);
      const subLoad = jest
        .spyOn(TestBed.inject(SubscriptionsStore), 'reloadQuietly')
        .mockImplementation(() => undefined);
      const savedSearchesLoad = jest
        .spyOn(TestBed.inject(SavedSearchesStore), 'load')
        .mockImplementation(() => undefined);

      if (options.confirmAnswer !== undefined) closed.next(options.confirmAnswer);

      return { actions, http, dialogOpen, subLoad, savedSearchesLoad, closed };
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

    it('opens the add-feed dialog and reloads on a successful subscribe', () => {
      const { actions, dialogOpen, subLoad, closed } = setup();
      closed.next(SUBSCRIPTION);

      actions.addFeed().subscribe();

      expect(dialogOpen).toHaveBeenCalled();
      expect(subLoad).toHaveBeenCalled();
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
        expect(confirmMessage([subscriptions(1)[0], bluesky])).toContain(
          'move to their Saved feeds',
        );
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

  describe('retryFeed', () => {
    const refreshReport = (over: Partial<RefreshReport>): RefreshReport => ({
      status: 'completed',
      progress: { done: 1, total: 1 },
      fetched: 0,
      notModified: 0,
      failed: 0,
      throttled: 0,
      skippedForBudget: 0,
      remaining: 0,
      pruned: 0,
      ...over,
    });
    const emptyResponse = () => ({
      subscriptions: [],
      favoritesCount: 0,
      unreadTotals: {
        allItems: 0,
        forYou: 0,
        tags: [],
        networks: [],
        groups: { tags: 0, feeds: 0 },
      },
      keptCount: 0,
      viewedCount: 0,
    });
    const flushReload = (http: HttpTestingController): void => {
      http.expectOne(`${BASE}/api/subscriptions`).flush(emptyResponse());
    };

    let http: HttpTestingController;
    afterEach(() => http.verify());

    // Own TestBed, same reason as the bulk-action setup(): ManageActions and
    // ToastService share the Dialog token, so a real ToastService.show() call
    // here needs the stub replaced rather than left to the outer `open` mock.
    function setup() {
      TestBed.resetTestingModule();
      const toast = { show: jest.fn() };
      TestBed.configureTestingModule({
        imports: [provideTranslocoTesting()],
        providers: [
          provideHttpClient(),
          provideHttpClientTesting(),
          { provide: API_BASE_URL, useValue: BASE },
          { provide: Dialog, useValue: { open } },
          { provide: ToastService, useValue: toast },
        ],
      });
      const actions = TestBed.inject(ManageActions);
      http = TestBed.inject(HttpTestingController);
      // Resolve the store, else the post-retry quiet reload no-ops — exactly as
      // it does before a page has loaded its subscriptions.
      const store = TestBed.inject(SubscriptionsStore);
      store.load();
      http.expectOne(`${BASE}/api/subscriptions`).flush(emptyResponse());
      // Spy without a mock implementation: translate() still runs for real
      // (the shipped en/de dictionaries), so a missing `settings.health.retry.*`
      // key still resolves the same way production would — the spy only lets
      // the tests see exactly which key and params retryFeed passed in, which
      // the rendered (key-echoing) string alone cannot distinguish.
      const translate = jest.spyOn(TestBed.inject(TranslocoService), 'translate');
      return { actions, http, toast, translate };
    }

    it('POSTs a single-feed refresh scoped by feedId', () => {
      const { actions, http } = setup();

      actions.retryFeed(sub).subscribe();

      const testRequest = http.expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`);
      expect(testRequest.request.method).toBe('POST');
      testRequest.flush(refreshReport({ failed: 0, fetched: 1 }));
      flushReload(http);
    });

    it('emits false, toasts recovery and quietly reloads when nothing failed', () => {
      const { actions, http, toast, translate } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 0, fetched: 1, notModified: 0 }));

      expect(stillFailing).toBe(false);
      expect(translate).toHaveBeenCalledWith('settings.health.retry.recovered', {
        title: sub.title,
      });
      expect(toast.show).toHaveBeenCalledWith(
        expect.objectContaining({ durationMs: CONFIRMATION_DURATION_MS }),
      );
      flushReload(http);
    });

    it('treats a pure 304 (no fetch, no failure) as recovered', () => {
      const { actions, http, translate } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 0, fetched: 0, notModified: 1 }));

      expect(stillFailing).toBe(false);
      expect(translate).toHaveBeenCalledWith('settings.health.retry.recovered', {
        title: sub.title,
      });
      flushReload(http);
    });

    it('emits true and shows no toast when the feed is still failing', () => {
      const { actions, http, toast } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush(refreshReport({ failed: 1, fetched: 0, notModified: 0 }));

      expect(stillFailing).toBe(true);
      expect(toast.show).not.toHaveBeenCalled();
      flushReload(http);
    });

    it('emits true and does not reload when the refresh request fails', () => {
      const { actions, http, toast } = setup();

      let stillFailing: boolean | undefined;
      actions.retryFeed(sub).subscribe((failing) => (stillFailing = failing));
      http
        .expectOne(`${BASE}/api/refresh?feedId=${sub.feedId}`)
        .flush('fail', { status: 500, statusText: 'Server Error' });

      expect(stillFailing).toBe(true);
      expect(toast.show).not.toHaveBeenCalled();
      http.expectNone(`${BASE}/api/subscriptions`);
    });
  });
});
