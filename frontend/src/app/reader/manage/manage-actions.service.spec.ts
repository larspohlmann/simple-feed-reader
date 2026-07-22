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
  createdAt: 'x',
  tags: [],
  unreadCount: 0,
};
const tag: TagDto = { id: 3, name: 'Tech', color: null, icon: null };

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

  it('createTag: reloads tags when the dialog returns a tag', () => {
    closed = tag;
    const tagSpy = jest
      .spyOn(TestBed.inject(TagsStore), 'load')
      .mockImplementation(() => undefined);
    svc.createTag();
    expect(tagSpy).toHaveBeenCalled();
  });
});
