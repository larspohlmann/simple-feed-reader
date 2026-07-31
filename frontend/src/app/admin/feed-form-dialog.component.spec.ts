import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { AdminCatalogCategoryDto, AdminCatalogFeedDto } from './admin.models';
import { FeedFormDialogComponent, FeedFormData } from './feed-form-dialog.component';

const categories: AdminCatalogCategoryDto[] = [
  {
    id: 1,
    key: 'a',
    name: 'A',
    icon: '',
    color: '#112233',
    position: 0,
    enabled: true,
    locked: false,
  },
  {
    id: 2,
    key: 'b',
    name: 'B',
    icon: '',
    color: '#112233',
    position: 1,
    enabled: true,
    locked: false,
  },
];

const feed: AdminCatalogFeedDto = {
  id: 5,
  categoryId: 1,
  title: 'Ars',
  url: 'https://example.test/feed',
  siteUrl: null,
  description: null,
  sourceFormat: 'xml',
  position: 0,
  enabled: true,
  locked: false,
  faviconFetchedAt: null,
  faviconFailedAt: null,
};

describe('FeedFormDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();

  function mount(data: FeedFormData) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(FeedFormDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => close.mockClear());
  afterEach(() => ctrl.verify());

  it('prefills from the edited feed and PUTs on submit', () => {
    const f = mount({ feed, categories, categoryId: 1 });
    const c = f.componentInstance;
    expect(c.form.getRawValue().title).toBe('Ars');

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/feeds/5');
    expect(req.request.body).toMatchObject({
      title: 'Ars',
      url: 'https://example.test/feed',
      categoryId: 1,
      sourceFormat: 'xml',
    });
    req.flush({ feed });
    expect(close).toHaveBeenCalledWith(feed);
  });

  it('creates a new feed preselecting the opening category, empty strings as null', () => {
    const f = mount({ feed: null, categories, categoryId: 2 });
    const c = f.componentInstance;
    expect(c.form.getRawValue().categoryId).toBe(2);
    c.form.patchValue({ title: 'New', url: 'https://example.test/new' });

    c.submit();
    const req = ctrl.expectOne('https://api.test/api/admin/catalog/feeds');
    expect(req.request.body).toMatchObject({
      title: 'New',
      categoryId: 2,
      siteUrl: null,
      description: null,
      sourceFormat: 'xml',
    });
    req.flush({ feed });
    expect(close).toHaveBeenCalled();
  });

  it('does not submit without title and url', () => {
    const f = mount({ feed: null, categories, categoryId: 1 });
    f.componentInstance.submit();
    ctrl.expectNone('https://api.test/api/admin/catalog/feeds');
  });
});
