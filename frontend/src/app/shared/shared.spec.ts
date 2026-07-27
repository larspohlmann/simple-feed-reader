import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { FormErrorComponent } from './form-error/form-error.component';
import { SpinnerComponent } from './spinner/spinner.component';

// The button has its own spec (button.component.spec.ts) now that it carries
// variants, sizes and an opt-in full width; duplicating its loading case here
// would only be a second place to forget.
@Component({
  imports: [FormErrorComponent, SpinnerComponent],
  template: `
    <app-form-error [message]="'Bad input'" />
    <app-spinner />
  `,
})
class Host {}

describe('shared primitives', () => {
  it('form error renders its message', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('app-form-error')?.textContent).toContain('Bad input');
  });

  it('spinner exposes an accessible status role', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('app-spinner [role="status"]'),
    ).toBeTruthy();
  });
});
