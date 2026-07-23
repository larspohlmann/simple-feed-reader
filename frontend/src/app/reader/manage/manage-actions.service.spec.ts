import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { ManageActions } from './manage-actions.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { TagsStore } from '../tags.store';
import { SubscriptionDto, TagDto } from '../models';

const sub: SubscriptionDto = {
  id: 5,
  title: 'Heise',
  customTitle: null,
  feedUrl: 'u',
  siteUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  position: 0,
  tags: [],
  unreadCount: 0,
};
const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null, position: 0 };

describe('ManageActions', () => {
  let svc: ManageActions;
  let ctrl: HttpTestingController;
  let closed: unknown;
  const open = jest.fn(() => ({ closed: of(closed) }));

  beforeEach(() => {
    TestBed.configureTestingModule({
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
});
