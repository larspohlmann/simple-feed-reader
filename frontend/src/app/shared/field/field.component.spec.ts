import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { FieldComponent } from './field.component';

@Component({
  imports: [FieldComponent],
  template: `
    <app-field [label]="label()" [error]="error()" [required]="required()">
      <input id="probe" />
    </app-field>
  `,
})
class Host {
  readonly label = signal('Name');
  readonly error = signal<string | null>(null);
  readonly required = signal(false);
}

describe('FieldComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('renders the label and projects the control', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.querySelector('label')?.textContent).toContain('Name');
    expect(el.querySelector('input#probe')).not.toBeNull();
  });

  it('shows no error region until an error is set', async () => {
    const fixture = await mount();
    expect(fixture.nativeElement.querySelector('.error')).toBeNull();

    fixture.componentInstance.error.set('Required');
    fixture.detectChanges();

    const error: HTMLElement = fixture.nativeElement.querySelector('.error');
    expect(error.textContent).toContain('Required');
    expect(error.getAttribute('role')).toBe('alert');
  });

  it('marks the label when the field is required', async () => {
    const fixture = await mount();
    expect(fixture.nativeElement.querySelector('.required')).toBeNull();

    fixture.componentInstance.required.set(true);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.required')).not.toBeNull();
  });
});
