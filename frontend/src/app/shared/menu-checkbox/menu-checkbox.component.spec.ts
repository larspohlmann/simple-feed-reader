import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MenuCheckboxComponent } from './menu-checkbox.component';

@Component({
  imports: [MenuCheckboxComponent],
  template: `<app-menu-checkbox
    label="Show in All items"
    [checked]="checked()"
    (toggled)="toggles = toggles + 1"
  />`,
  host: { '(click)': 'containerClicks = containerClicks + 1' },
})
class HostComponent {
  readonly checked = signal(true);
  toggles = 0;
  containerClicks = 0;
}

describe('MenuCheckboxComponent', () => {
  let fixture: ComponentFixture<HostComponent>;

  const input = (): HTMLInputElement =>
    fixture.nativeElement.querySelector('input[role="menuitemcheckbox"]');

  beforeEach(async () => {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
  });

  it('reflects checked as the box and aria-checked, with its label', () => {
    expect(input().checked).toBe(true);
    expect(input().getAttribute('aria-checked')).toBe('true');
    expect(fixture.nativeElement.textContent).toContain('Show in All items');

    fixture.componentInstance.checked.set(false);
    fixture.detectChanges();

    expect(input().checked).toBe(false);
    expect(input().getAttribute('aria-checked')).toBe('false');
  });

  it('emits toggled once per click without the click reaching a container', () => {
    input().click();
    fixture.detectChanges();

    expect(fixture.componentInstance.toggles).toBe(1);
    expect(fixture.componentInstance.containerClicks).toBe(0);
  });
});
