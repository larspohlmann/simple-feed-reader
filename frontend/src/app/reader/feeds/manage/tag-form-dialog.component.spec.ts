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

  const tagOf = (id: number, name: string, parentId: number | null): TagDto => ({
    id,
    name,
    color: null,
    icon: null,
    position: 0,
    parentId,
  });

  function mount(data: TagDto | null, tags: TagDto[] = []) {
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
    ctrl = TestBed.inject(HttpTestingController);
    fixture.detectChanges();
    ctrl.expectOne('https://api.test/api/tags').flush({ tags });
    fixture.detectChanges();
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
    expect(testRequest.request.body).toEqual({
      name: 'Tech',
      color: '#3f8676',
      icon: 'code',
      parentId: null,
    });
    testRequest.flush({ tag: { id: 9, name: 'Tech', color: '#3f8676', icon: 'code' } });
    expect(close).toHaveBeenCalledWith({ id: 9, name: 'Tech', color: '#3f8676', icon: 'code' });
  });

  it('edits a tag (PATCH) prefilled from data', () => {
    const fixture = mount({
      id: 4,
      name: 'Old',
      color: '#4f7cac',
      icon: 'label',
      position: 0,
      parentId: null,
    });
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

  it('keeps the existing parent when a child is edited without touching the select', () => {
    const fixture = mount(tagOf(2, 'Long reads', 1), [
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
    ]);
    fixture.componentInstance.form.controls.name.setValue('Essays');
    fixture.componentInstance.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags/2');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      name: 'Essays',
      color: null,
      icon: null,
      parentId: 1,
    });
    testRequest.flush({ tag: tagOf(2, 'Essays', 1) });
  });

  it('offers the root tags except itself as parents', () => {
    const fixture = mount(tagOf(4, 'Old', null), [
      tagOf(4, 'Old', null),
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
    ]);
    const values = [
      ...(fixture.nativeElement as HTMLElement).querySelectorAll('select#tag-parent option'),
    ].map((option) => (option as HTMLOptionElement).value);
    expect(values).toEqual(['', '1']);
  });

  it('sends the chosen parent', () => {
    const fixture = mount(null, [tagOf(1, 'Reading', null)]);
    fixture.componentInstance.form.controls.name.setValue('Long reads');
    fixture.componentInstance.parentId.set(1);
    fixture.componentInstance.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags');
    expect(testRequest.request.body).toEqual({
      name: 'Long reads',
      color: null,
      icon: null,
      parentId: 1,
    });
    testRequest.flush({ tag: tagOf(9, 'Long reads', 1) });
  });

  it('locks the parent for a tag that has children and sends null', () => {
    const fixture = mount(tagOf(1, 'Reading', null), [
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
    ]);
    const select = (fixture.nativeElement as HTMLElement).querySelector(
      'select#tag-parent',
    ) as HTMLSelectElement;
    expect(select.disabled).toBe(true);
    fixture.componentInstance.parentId.set(3);
    fixture.componentInstance.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/tags/1');
    expect(testRequest.request.body).toEqual({
      name: 'Reading',
      color: null,
      icon: null,
      parentId: null,
    });
    testRequest.flush({ tag: tagOf(1, 'Reading', null) });
  });

  it('surfaces a 422 on the parent under the parent field and stays open', () => {
    const fixture = mount(null, [tagOf(1, 'Reading', null)]);
    const component = fixture.componentInstance;
    component.form.controls.name.setValue('Essays');
    component.parentId.set(1);
    component.submit();
    ctrl.expectOne('https://api.test/api/tags').flush(
      {
        type: 'invalid_tag_parent',
        title: 'Invalid parent tag',
        status: 422,
        detail: 'A parent tag cannot itself have a parent.',
        errors: { parentId: ['A parent tag cannot itself have a parent.'] },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.parentError()).toBe('A parent tag cannot itself have a parent.');
    expect(component.error()).toBeNull();
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'A parent tag cannot itself have a parent.',
    );
    expect(close).not.toHaveBeenCalled();
  });

  it('clears the parent error once another parent is chosen', () => {
    const fixture = mount(null, [tagOf(1, 'Reading', null)]);
    const component = fixture.componentInstance;
    component.form.controls.name.setValue('Essays');
    component.parentId.set(1);
    component.submit();
    ctrl.expectOne('https://api.test/api/tags').flush(
      {
        type: 'invalid_tag_parent',
        title: 'Invalid parent tag',
        status: 422,
        errors: { parentId: ['A parent tag cannot itself have a parent.'] },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    expect(component.parentError()).not.toBeNull();

    component.onParentChange({ target: { value: '' } } as unknown as Event);

    expect(component.parentError()).toBeNull();
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
