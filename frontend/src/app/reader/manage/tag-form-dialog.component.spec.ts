import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { TagFormDialogComponent } from './tag-form-dialog.component';
import { TagDto } from '../models';

describe('TagFormDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount(data: TagDto | null) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: data },
      ],
    });
    const f = TestBed.createComponent(TagFormDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return f;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('creates a tag (POST) and closes with it', () => {
    const f = mount(null);
    const c = f.componentInstance;
    c.form.controls.name.setValue('Tech');
    c.icon.set('code');
    c.color.set('#3f8676');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/tags');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
    req.flush({ tag: { id: 9, name: 'Tech', color: '#3f8676', icon: 'code' } });
    expect(close).toHaveBeenCalledWith({ id: 9, name: 'Tech', color: '#3f8676', icon: 'code' });
  });

  it('edits a tag (PATCH) prefilled from data', () => {
    const f = mount({ id: 4, name: 'Old', color: '#4f7cac', icon: 'label' });
    const c = f.componentInstance;
    expect(c.form.getRawValue().name).toBe('Old');
    expect(c.color()).toBe('#4f7cac');
    c.form.controls.name.setValue('New');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/tags/4');
    expect(req.request.method).toBe('PATCH');
    req.flush({ tag: { id: 4, name: 'New', color: '#4f7cac', icon: 'label' } });
    expect(close).toHaveBeenCalled();
  });

  it('surfaces a 409 name-taken error inline and stays open', () => {
    const f = mount(null);
    const c = f.componentInstance;
    c.form.controls.name.setValue('Dup');
    c.submit();
    ctrl
      .expectOne('https://api.test/api/tags')
      .flush(
        { type: 'about:blank', title: 'Tag name already in use', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    expect(c.error()).toBe('Tag name already in use');
    expect(close).not.toHaveBeenCalled();
  });

  it('does not submit an empty name', () => {
    const c = mount(null).componentInstance;
    c.submit();
    ctrl.expectNone('https://api.test/api/tags');
  });
});
