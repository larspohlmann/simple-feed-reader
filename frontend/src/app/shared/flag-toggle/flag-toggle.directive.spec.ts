import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { FlagToggleDirective } from './flag-toggle.directive';

@Component({
  imports: [FlagToggleDirective],
  template: `<button type="button" [appFlagToggle]="active()">Keep</button>`,
})
class Host {
  readonly active = signal(false);
}

describe('FlagToggleDirective', () => {
  function mount() {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const button: HTMLButtonElement = fixture.nativeElement.querySelector('button');
    return { fixture, button };
  }

  it('stamps the shared class and reports an inactive flag as unpressed', () => {
    const { button } = mount();
    expect(button.classList).toContain('flag-toggle');
    expect(button.classList).not.toContain('on');
    expect(button.getAttribute('aria-pressed')).toBe('false');
  });

  it('marks an active flag on and pressed, and follows the state', () => {
    const { fixture, button } = mount();
    fixture.componentInstance.active.set(true);
    fixture.detectChanges();
    expect(button.classList).toContain('on');
    expect(button.getAttribute('aria-pressed')).toBe('true');

    fixture.componentInstance.active.set(false);
    fixture.detectChanges();
    expect(button.classList).not.toContain('on');
  });
});
