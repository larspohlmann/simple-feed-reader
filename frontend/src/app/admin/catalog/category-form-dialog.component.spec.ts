import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { AdminCatalogCategoryDto } from '../admin.models';
import { CategoryFormDialogComponent } from './category-form-dialog.component';

const category: AdminCatalogCategoryDto = {
  id: 7,
  key: 'tech',
  name: 'Tech',
  icon: 'memory',
  color: '#112233',
  position: 0,
  enabled: true,
  locked: false,
};

describe('CategoryFormDialogComponent', () => {
  let ctrl: HttpTestingController;
  const close = jest.fn();

  function mount(data: AdminCatalogCategoryDto | null) {
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
    const fixture = TestBed.createComponent(CategoryFormDialogComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  beforeEach(() => close.mockClear());
  afterEach(() => ctrl.verify());

  it('prefills from the edited category and PUTs on submit, closing with the result', () => {
    const fixture = mount(category);
    const component = fixture.componentInstance;
    expect(component.form.getRawValue().name).toBe('Tech');

    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/catalog/categories/7');
    expect(testRequest.request.body).toMatchObject({ name: 'Tech', color: '#112233', key: 'tech' });
    testRequest.flush({ category });
    expect(close).toHaveBeenCalledWith(category);
  });

  it('POSTs a new category with the default colour and an empty key', () => {
    const fixture = mount(null);
    const component = fixture.componentInstance;
    component.form.patchValue({ name: 'News' });

    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/admin/catalog/categories');
    expect(testRequest.request.body).toMatchObject({ name: 'News', key: '' });
    testRequest.flush({ category });
    expect(close).toHaveBeenCalled();
  });

  it('does not submit an empty name', () => {
    const fixture = mount(null);
    fixture.componentInstance.submit();
    ctrl.expectNone('https://api.test/api/admin/catalog/categories');
  });
});
