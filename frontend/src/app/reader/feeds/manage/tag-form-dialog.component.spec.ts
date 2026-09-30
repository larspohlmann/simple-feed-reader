import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../../core/api';
import { TagFormDialogComponent } from './tag-form-dialog.component';
import { TagDto } from '../../models';

describe('TagFormDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount(data: TagDto | null) {
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
    const fixture = TestBed.createComponent(TagFormDialogComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    return fixture;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('creates a tag (POST) and closes with it', () => {
    const fixture = mount(null);
    const component = fixture.componentInstance;
    component.form.controls.name.setValue('Tech');
    component.icon.set('code');
    component.color.set('#3f8676');
    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags');
    expect(testRequest.request.method).toBe('POST');
    expect(testRequest.request.body).toEqual({ name: 'Tech', color: '#3f8676', icon: 'code' });
    testRequest.flush({ tag: { id: 9, name: 'Tech', color: '#3f8676', icon: 'code' } });
    expect(close).toHaveBeenCalledWith({ id: 9, name: 'Tech', color: '#3f8676', icon: 'code' });
  });

  it('edits a tag (PATCH) prefilled from data', () => {
    const fixture = mount({ id: 4, name: 'Old', color: '#4f7cac', icon: 'label', position: 0 });
    const component = fixture.componentInstance;
    expect(component.form.getRawValue().name).toBe('Old');
    expect(component.color()).toBe('#4f7cac');
    component.form.controls.name.setValue('New');
    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags/4');
    expect(testRequest.request.method).toBe('PATCH');
    testRequest.flush({ tag: { id: 4, name: 'New', color: '#4f7cac', icon: 'label' } });
    expect(close).toHaveBeenCalled();
  });

  it('surfaces a 409 name-taken error inline and stays open', () => {
    const fixture = mount(null);
    const component = fixture.componentInstance;
    component.form.controls.name.setValue('Dup');
    component.submit();
    ctrl
      .expectOne('https://api.test/api/tags')
      .flush(
        { type: 'about:blank', title: 'Tag name already in use', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    expect(component.error()).toBe('Tag name already in use');
    expect(close).not.toHaveBeenCalled();
  });

  it('drives the colour and icon signals from the shared pickers', () => {
    const fixture = mount(null);
    const component = fixture.componentInstance;
    const host: HTMLElement = fixture.nativeElement;

    (host.querySelector('app-color-field .swatch') as HTMLButtonElement).click();
    expect(component.color()).toBe('#3f8676');

    // The grid is inline here, so an icon is one click away -- no trigger.
    expect(host.querySelector('app-icon-picker .trigger')).toBeNull();
    (
      host.querySelector('app-icon-picker .grid .opt[aria-label="code"]') as HTMLButtonElement
    ).click();
    expect(component.icon()).toBe('code');

    // The "no icon" option is the first in the grid and clears back to null.
    fixture.detectChanges();
    (host.querySelector('app-icon-picker .grid .opt') as HTMLButtonElement).click();
    expect(component.icon()).toBeNull();
  });

  it('does not submit an empty name', () => {
    const component = mount(null).componentInstance;
    component.submit();
    ctrl.expectNone('https://api.test/api/tags');
  });
});
