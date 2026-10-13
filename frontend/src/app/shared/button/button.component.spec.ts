import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { ButtonComponent, ButtonVariant } from './button.component';

@Component({
  imports: [ButtonComponent],
  template: `<app-button
    [variant]="variant()"
    [loading]="loading()"
    [disabled]="disabled()"
    [ariaLabel]="label()"
    (click)="presses = presses + 1"
    >Save</app-button
  >`,
})
class Host {
  readonly variant = signal<ButtonVariant>('default');
  readonly loading = signal(false);
  readonly disabled = signal(false);
  presses = 0;
  readonly label = signal<string | undefined>(undefined);
}

describe('ButtonComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };
  const buttonOf = (fixture: ComponentFixture<Host>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('button') as HTMLButtonElement;
  const hostOf = (fixture: ComponentFixture<Host>): HTMLElement =>
    fixture.nativeElement.querySelector('app-button') as HTMLElement;

  it('carries the variant as a class', async () => {
    const fixture = await mount();
    expect(buttonOf(fixture).classList.contains('primary')).toBe(false);

    fixture.componentInstance.variant.set('danger');
    fixture.detectChanges();
    expect(buttonOf(fixture).classList.contains('danger')).toBe(true);
  });

  // Filled danger confirms a destructive action, outlined danger initiates one;
  // they are two weights, not two names for the same thing.
  it('keeps the two destructive weights apart', async () => {
    const fixture = await mount();

    fixture.componentInstance.variant.set('danger');
    fixture.detectChanges();
    expect(buttonOf(fixture).classList.contains('danger')).toBe(true);
    expect(buttonOf(fixture).classList.contains('danger-outline')).toBe(false);

    fixture.componentInstance.variant.set('danger-outline');
    fixture.detectChanges();
    expect(buttonOf(fixture).classList.contains('danger-outline')).toBe(true);
    expect(buttonOf(fixture).classList.contains('danger')).toBe(false);
  });

  // An icon-only action (Edit/Delete collapsed on a narrow screen) hides its
  // text, so the accessible name has to come from aria-label instead. Absent by
  // default so a labelled button is named by its own text, not a duplicate.
  it('names the button from ariaLabel only when one is given', async () => {
    const fixture = await mount();
    expect(buttonOf(fixture).getAttribute('aria-label')).toBeNull();

    fixture.componentInstance.label.set('Edit');
    fixture.detectChanges();
    expect(buttonOf(fixture).getAttribute('aria-label')).toBe('Edit');
  });

  it('swaps the label for a spinner and disables while loading', async () => {
    const fixture = await mount();
    fixture.componentInstance.loading.set(true);
    fixture.detectChanges();

    expect(buttonOf(fixture).disabled).toBe(true);
    expect(fixture.nativeElement.querySelector('app-spinner')).not.toBeNull();
    expect(buttonOf(fixture).textContent?.trim()).toBe('');
  });

  it('passes a click on its host through while enabled', async () => {
    const fixture = await mount();
    hostOf(fixture).click();

    expect(fixture.componentInstance.presses).toBe(1);
  });

  it('swallows a click on its host while disabled or loading', async () => {
    const fixture = await mount();

    fixture.componentInstance.disabled.set(true);
    fixture.detectChanges();
    hostOf(fixture).click();
    fixture.componentInstance.disabled.set(false);
    fixture.componentInstance.loading.set(true);
    fixture.detectChanges();
    hostOf(fixture).click();

    expect(fixture.componentInstance.presses).toBe(0);
  });

  it('marks a disabled button off, but not a loading one', async () => {
    const fixture = await mount();
    fixture.componentInstance.variant.set('primary');
    fixture.componentInstance.disabled.set(true);
    fixture.detectChanges();
    expect(buttonOf(fixture).classList).toContain('off');
    expect(buttonOf(fixture).classList).toContain('primary');

    fixture.componentInstance.loading.set(true);
    fixture.detectChanges();
    expect(buttonOf(fixture).classList).not.toContain('off');
  });
});
