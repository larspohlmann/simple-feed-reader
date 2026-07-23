import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { SubscriptionDto } from '../models';

const sub: SubscriptionDto = {
  id: 5,
  title: 'Heise',
  customTitle: null,
  feedUrl: 'https://heise.de/rss',
  siteUrl: 'https://heise.de',
  status: 'active',
  createdAt: 'x',
  position: 0,
  tags: [{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }],
  unreadCount: 3,
};

describe('EditSubscriptionDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: sub },
      ],
    });
    const f = TestBed.createComponent(EditSubscriptionDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    // ngOnInit loads all tags:
    ctrl.expectOne('https://api.test/api/tags').flush({
      tags: [
        { id: 1, name: 'Tech', color: null, icon: null },
        { id: 2, name: 'News', color: null, icon: null },
      ],
    });
    return f;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('prefills the current tags as checked', () => {
    const c = mount().componentInstance;
    expect(c.checked().has(1)).toBe(true);
    expect(c.checked().has(2)).toBe(false);
  });

  it('PATCHes customTitle (empty → null) and the toggled tag set', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('  My Heise ');
    c.toggle(2); // add News
    c.toggle(1); // remove Tech
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ customTitle: 'My Heise', tagIds: [2] });
    req.flush({ subscription: { ...sub, customTitle: 'My Heise' } });
    expect(close).toHaveBeenCalled();
  });

  it('sends customTitle null when cleared', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.body).toEqual({ customTitle: null, tagIds: [1] });
    req.flush({ subscription: sub });
  });
});
