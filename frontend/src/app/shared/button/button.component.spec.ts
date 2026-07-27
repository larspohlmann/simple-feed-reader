import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { ButtonComponent } from './button.component';

@Component({
  imports: [ButtonComponent],
  template: `<app-button [variant]="variant()" [loading]="loading()">Save</app-button>`,
})
class Host {
  readonly variant = signal<'default' | 'primary' | 'danger' | 'ghost'>('default');
  readonly loading = signal(false);
}

describe('ButtonComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('carries the variant as a class', async () => {
    const fixture = await mount();
    const button = () => fixture.nativeElement.querySelector('button') as HTMLElement;
    expect(button().classList.contains('primary')).toBe(false);

    fixture.componentInstance.variant.set('danger');
    fixture.detectChanges();
    expect(button().classList.contains('danger')).toBe(true);
  });

  it('swaps the label for a spinner and disables while loading', async () => {
    const fixture = await mount();
    fixture.componentInstance.loading.set(true);
    fixture.detectChanges();

    const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(fixture.nativeElement.querySelector('app-spinner')).not.toBeNull();
    expect(button.textContent?.trim()).toBe('');
  });
});
