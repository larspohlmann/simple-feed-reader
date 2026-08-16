import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { FieldComponent } from './field.component';

@Component({
  imports: [FieldComponent],
  template: `
    <app-field [label]="label()" [error]="error()" [required]="required()" [info]="info()">
      <input id="probe" />
    </app-field>
  `,
})
class Host {
  readonly label = signal('Name');
  readonly error = signal<string | null>(null);
  readonly required = signal(false);
  readonly info = signal<string | null>(null);
}

describe('FieldComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  const mountField = async (overrides: {
    label?: string;
    error?: string | null;
    required?: boolean;
    info?: string | null;
  }) => {
    const fixture = await mount();
    if (overrides.label !== undefined) {
      fixture.componentInstance.label.set(overrides.label);
    }
    if (overrides.error !== undefined) {
      fixture.componentInstance.error.set(overrides.error);
    }
    if (overrides.required !== undefined) {
      fixture.componentInstance.required.set(overrides.required);
    }
    if (overrides.info !== undefined) {
      fixture.componentInstance.info.set(overrides.info);
    }
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

  it('renders an info tip named after the field when info is set', async () => {
    const fixture = await mountField({ label: 'Endpoint', info: 'What this endpoint is for.' });

    const trigger = fixture.nativeElement.querySelector(
      'app-info-tip button.trigger',
    ) as HTMLButtonElement;
    expect(trigger).not.toBeNull();
    expect(trigger.getAttribute('aria-label')).toBe('Endpoint');

    trigger.click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-info-tip .panel')?.textContent).toContain(
      'What this endpoint is for.',
    );
  });

  it('renders no info tip without info', async () => {
    const fixture = await mountField({ label: 'Endpoint' });

    expect(fixture.nativeElement.querySelector('app-info-tip')).toBeNull();
  });

  /**
   * The tip belongs to the label row, and its panel opens on the row's next
   * line — between the label and the control, under the ⓘ that was clicked.
   * The tip used to sit after the projected control with only its trigger
   * lifted into the label row, which opened the explanation below the input
   * instead (#433). jsdom has no layout, so this pins the document order the
   * arrangement rests on, not the resulting geometry.
   */
  it('keeps the tip and its panel in the label row, ahead of the control', async () => {
    const fixture = await mountField({ label: 'Endpoint', info: 'What this endpoint is for.' });
    const row: HTMLElement = fixture.nativeElement.querySelector('.lbl-row');

    (row.querySelector('button.trigger') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(row.querySelector('.panel')).not.toBeNull();

    const control: HTMLElement = fixture.nativeElement.querySelector('input#probe');
    expect(row.compareDocumentPosition(control) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  /**
   * The tip lives inside the `<label>` that names the control, so a click that
   * reached the label would toggle that control. Both of the tip's own
   * surfaces swallow their clicks; this pins that the field keeps benefiting
   * from it.
   */
  it('lets neither the trigger nor the panel activate the label', async () => {
    const fixture = await mountField({ label: 'Endpoint', info: 'What this endpoint is for.' });
    const label: HTMLLabelElement = fixture.nativeElement.querySelector('label');
    const reached = jest.fn();
    label.addEventListener('click', reached);

    (label.querySelector('button.trigger') as HTMLButtonElement).click();
    fixture.detectChanges();
    (label.querySelector('.panel') as HTMLElement).click();

    expect(reached).not.toHaveBeenCalled();
  });
});
