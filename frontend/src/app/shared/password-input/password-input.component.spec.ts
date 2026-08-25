import { Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { PasswordInputComponent } from './password-input.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

@Component({
  imports: [PasswordInputComponent],
  template: `<app-password-input
    ><input type="password" value="s3cret" autocomplete="current-password"
  /></app-password-input>`,
})
class HostComponent {}

describe('PasswordInputComponent', () => {
  function mount(): ComponentFixture<HostComponent> {
    TestBed.configureTestingModule({
      imports: [HostComponent, provideTranslocoTesting()],
    });
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  const input = (fixture: ComponentFixture<HostComponent>): HTMLInputElement =>
    fixture.nativeElement.querySelector('input') as HTMLInputElement;

  const toggle = (fixture: ComponentFixture<HostComponent>): HTMLButtonElement =>
    fixture.nativeElement.querySelector('button.toggle') as HTMLButtonElement;

  const iconName = (fixture: ComponentFixture<HostComponent>): string =>
    fixture.nativeElement.querySelector('.material-symbols-outlined')!.textContent!.trim();

  it('renders masked, with a show affordance', () => {
    const fixture = mount();

    expect(input(fixture).type).toBe('password');
    expect(toggle(fixture).getAttribute('type')).toBe('button');
    expect(toggle(fixture).getAttribute('aria-pressed')).toBe('false');
    expect(toggle(fixture).getAttribute('aria-label')).toBe('Show password');
    expect(iconName(fixture)).toBe('visibility');
  });

  it('reveals the secret on click', () => {
    const fixture = mount();

    toggle(fixture).click();
    fixture.detectChanges();

    expect(input(fixture).type).toBe('text');
    expect(toggle(fixture).getAttribute('aria-pressed')).toBe('true');
    expect(toggle(fixture).getAttribute('aria-label')).toBe('Hide password');
    expect(iconName(fixture)).toBe('visibility_off');
  });

  it('masks it again on a second click', () => {
    const fixture = mount();

    toggle(fixture).click();
    fixture.detectChanges();
    toggle(fixture).click();
    fixture.detectChanges();

    expect(input(fixture).type).toBe('password');
    expect(iconName(fixture)).toBe('visibility');
  });

  it('keeps focus in the field: the toggle refuses it, so mousedown default is prevented', () => {
    const fixture = mount();

    const mousedown = new MouseEvent('mousedown', { cancelable: true, bubbles: true });
    toggle(fixture).dispatchEvent(mousedown);

    expect(mousedown.defaultPrevented).toBe(true);
  });

  it('leaves the projected input in place, its bindings untouched', () => {
    const fixture = mount();

    // The same native node the consumer wrote, not a copy: its autocomplete and
    // value survive because the component only ever flips `type`.
    expect(input(fixture).getAttribute('autocomplete')).toBe('current-password');
    expect(input(fixture).value).toBe('s3cret');
  });
});
