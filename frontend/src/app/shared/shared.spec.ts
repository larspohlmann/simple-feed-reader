import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { ButtonComponent } from './button/button.component';
import { FormErrorComponent } from './form-error/form-error.component';
import { SpinnerComponent } from './spinner/spinner.component';

@Component({
  imports: [ButtonComponent, FormErrorComponent, SpinnerComponent],
  template: `
    <app-button [loading]="true">Save</app-button>
    <app-form-error [message]="'Bad input'" />
    <app-spinner />
  `,
})
class Host {}

describe('shared primitives', () => {
  it('button shows a spinner when loading and disables itself', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;
    expect(el.querySelector('button')?.disabled).toBe(true);
    expect(el.querySelector('app-button app-spinner')).toBeTruthy();
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
