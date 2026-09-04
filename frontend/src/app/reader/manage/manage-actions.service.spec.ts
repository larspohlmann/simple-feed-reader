import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { ReplaySubject, of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ManageActions } from './manage-actions.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';
import { ToastService } from '../../shared/toast/toast.service';

const BASE = 'https://api.test';

const sub: SubscriptionDto = {
  id: 5,
  feedId: 50,
  title: 'Heise',
  faviconUrl: null,
  customTitle: null,
  feedUrl: 'u',
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [],
  unreadCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
};
const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0 };
const TAG = tag;
const SUBSCRIPTION = sub;

describe('ManageActions', () => {
  let svc: ManageActions;
  let ctrl: HttpTestingController;
  let closed: unknown;
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
    svc = TestBed.inject(ManageActions);
    ctrl = TestBed.inject(HttpTestingController);
    open.mockClear();
  });
  afterEach(() => ctrl.verify());

  it('reloads subscriptions after a successful edit', () => {
    closed = sub; // dialog closed with an updated subscription
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.editSubscription(sub);
    expect(spy).toHaveBeenCalled();
  });

  it('unsubscribe: on confirm, DELETEs then reloads', () => {
    closed = true; // confirm dialog returned true
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.unsubscribe(sub);
    ctrl.expectOne('https://api.test/api/subscriptions/5').flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('unsubscribe: on cancel, does nothing', () => {
    closed = undefined;
    svc.unsubscribe(sub);
    ctrl.expectNone('https://api.test/api/subscriptions/5');
  });

  it('deleteTag: on confirm, DELETEs then reloads tags + subs', () => {
    closed = true;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    const subSpy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.deleteTag(tag);
    ctrl.expectOne('https://api.test/api/tags/3').flush(null);
    expect(tagSpy).toHaveBeenCalled();
    expect(subSpy).toHaveBeenCalled();
  });

  it('retag: PATCHes the whole tag set (preserving customTitle) then reloads', () => {
    const named: SubscriptionDto = { ...sub, customTitle: 'My feed' };
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.retag(named, [3, 7]);
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ customTitle: 'My feed', tagIds: [3, 7] });
    req.flush({ subscription: { ...named, tags: [tag] } });
    expect(spy).toHaveBeenCalled();
  });

  it('setIncludeInAllItems: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const s: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.subscriptions.set([s]);
    const spy = jest.spyOn(store, 'load').mockImplementation(() => undefined);
    svc.setIncludeInAllItems(s, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInAllItems).toBe(false);
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({
      customTitle: s.customTitle,
      tagIds: [3],
      includeInAllItems: false,
      includeInForYou: true,
    });
    req.flush({ subscription: { ...s, includeInAllItems: false } });
    expect(spy).toHaveBeenCalled();
  });

  it('setIncludeInForYou: PATCHes the full body with the flag flipped and optimistically updates the store', () => {
    const s: SubscriptionDto = { ...sub, tags: [tag] };
    const store = TestBed.inject(SubscriptionsStore);
    store.subscriptions.set([s]);
    const spy = jest.spyOn(store, 'load').mockImplementation(() => undefined);
    svc.setIncludeInForYou(s, false);
    expect(store.subscriptions().find((x) => x.id === 5)!.includeInForYou).toBe(false);
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({
      customTitle: s.customTitle,
      tagIds: [3],
      includeInAllItems: true,
      includeInForYou: false,
    });
    req.flush({ subscription: { ...s, includeInForYou: false } });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTags: PATCHes /api/tags/reorder then reloads tags', () => {
    const spy = jest.spyOn(TestBed.inject(TagsStore), 'load').mockImplementation(() => undefined);
    svc.reorderTags([3, 1, 2]);
    const req = ctrl.expectOne('https://api.test/api/tags/reorder');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ tagIds: [3, 1, 2] });
    req.flush({ tags: [] });
    expect(spy).toHaveBeenCalled();
  });

  it('reorderUntagged: PATCHes /api/subscriptions/reorder then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.reorderUntagged([9, 8, 7]);
    const req = ctrl.expectOne('https://api.test/api/subscriptions/reorder');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ subscriptionIds: [9, 8, 7] });
    req.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('reorderTagFeeds: PATCHes /api/tags/{id}/feed-order then reloads subs', () => {
    const spy = jest
      .spyOn(TestBed.inject(SubscriptionsStore), 'load')
      .mockImplementation(() => undefined);
    svc.reorderTagFeeds(4, [2, 1]);
    const req = ctrl.expectOne('https://api.test/api/tags/4/feed-order');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ subscriptionIds: [2, 1] });
    req.flush(null);
    expect(spy).toHaveBeenCalled();
  });

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    svc.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });

  describe('bulk actions', () => {
    const subs = (count: number): SubscriptionDto[] =>
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
        .spyOn(TestBed.inject(SubscriptionsStore), 'load')
        .mockImplementation(() => undefined);

      if (options.confirmAnswer !== undefined) closed.next(options.confirmAnswer);

      return { actions, http, dialogOpen, subLoad, closed };
    }

    it('posts one bulk patch when a tag is added to several feeds', () => {
      const { actions, http } = setup();
      let emitted = false;

      actions.bulkAddTag([1, 2, 3], TAG).subscribe(() => (emitted = true));

      const req = http.expectOne(`${BASE}/api/subscriptions/bulk`);
      expect(req.request.method).toBe('PATCH');
      expect(req.request.body).toEqual({ subscriptionIds: [1, 2, 3], addTagIds: [TAG.id] });
      req.flush({ subscriptions: [] });

      expect(emitted).toBe(true);
    });

    it('posts removeTagIds when a tag is removed', () => {
      const { actions, http } = setup();
      let emitted = false;

      actions.bulkRemoveTag([4], TAG).subscribe(() => (emitted = true));

      const req = http.expectOne(`${BASE}/api/subscriptions/bulk`);
      expect(req.request.body).toEqual({
        subscriptionIds: [4],
        removeTagIds: [TAG.id],
      });
      req.flush({ subscriptions: [] });

      expect(emitted).toBe(true);
    });

    it('sends only the flag that was named', () => {
      const { actions, http } = setup();
      let emitted = false;

      actions.bulkSetFlags([7], { includeInAllItems: false }).subscribe(() => (emitted = true));

      const req = http.expectOne(`${BASE}/api/subscriptions/bulk`);
      expect(req.request.body).toEqual({
        subscriptionIds: [7],
        includeInAllItems: false,
      });
      req.flush({ subscriptions: [] });

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
      const { actions, http, subLoad } = setup();

      actions.bulkAddTag([1], TAG).subscribe();
      http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });

      expect(subLoad).toHaveBeenCalled();
    });

    it('asks the user to type the count from five feeds up', () => {
      const { actions, dialogOpen } = setup();

      actions.bulkUnsubscribe(subs(5)).subscribe();

      expect(dialogOpen.mock.calls[0][1].data.requireText).toBe('5');
    });

    it('does not ask for typed text at four feeds', () => {
      const { actions, dialogOpen } = setup();

      actions.bulkUnsubscribe(subs(4)).subscribe();

      expect(dialogOpen.mock.calls[0][1].data.requireText).toBeUndefined();
    });

    it('singularises the confirmation title at a selection of one', () => {
      const { actions, dialogOpen } = setup();

      actions.bulkUnsubscribe(subs(1)).subscribe();

      expect(dialogOpen.mock.calls[0][1].data.title).toBe('Unsubscribe from 1 feed?');
    });

    it('names at most five titles and counts the rest', () => {
      const { actions, dialogOpen } = setup();

      actions.bulkUnsubscribe(subs(7)).subscribe();

      const message: string = dialogOpen.mock.calls[0][1].data.message;
      expect(message).toContain('Feed 1');
      expect(message).toContain('Feed 5');
      expect(message).not.toContain('Feed 6');
    });

    it('writes nothing when the confirmation is dismissed', () => {
      const { actions, http } = setup({ confirmAnswer: false });
      let outcome: boolean | undefined;

      actions.bulkUnsubscribe(subs(2)).subscribe((ok) => (outcome = ok));

      expect(outcome).toBe(false);
      http.expectNone(`${BASE}/api/subscriptions/bulk-unsubscribe`);
    });

    it('unsubscribes and reloads after a confirmed bulk unsubscribe', () => {
      const { actions, http, subLoad } = setup({ confirmAnswer: true });

      actions.bulkUnsubscribe(subs(2)).subscribe();
      const req = http.expectOne(`${BASE}/api/subscriptions/bulk-unsubscribe`);
      expect(req.request.body).toEqual({ subscriptionIds: [1, 2] });
      req.flush({ removed: 2 });

      expect(subLoad).toHaveBeenCalled();
    });
  });
});
